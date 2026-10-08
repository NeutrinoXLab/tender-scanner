import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/notice-save.js', import.meta.url), 'utf8');
function setup(fetch) {
    const element = (extra = {}) => ({disabled: false, textContent: '', hidden: false, setAttribute() {}, removeAttribute() {}, classList: {add() {}, remove() {}}, ...extra});
    const button = element({textContent: 'Salvează'});
    const ignoreButton = element({textContent: 'Nu mai ignora'});
    const action = {value: 'save'};
    const ignoreAction = {value: 'restore'};
    const feedback = element();
    const saved = element({hidden: true});
    const ignored = element();
    const ignoreForm = {querySelector: s => s === 'button' ? ignoreButton : ignoreAction};
    const selectors = {'[data-save-feedback]': feedback, 'form:not([data-save-notice])': ignoreForm, '[data-saved-badge]': saved, '[data-ignored-badge]': ignored};
    const card = {querySelector: s => selectors[s], querySelectorAll: () => [button, ignoreButton]};
    let submit;
    const form = {action: {value: 'save'}, getAttribute: name => name === 'action' ? '/notices/1/personal' : null, dataset: {noticeTitle: 'Laptop'}, closest: () => card,
        querySelector: s => s === 'button' ? button : action, setAttribute() {}, removeAttribute() {},
        addEventListener: (event, handler) => { submit = handler; }};
    vm.runInNewContext(source, {document: {querySelectorAll: () => [form]}, fetch, FormData: class { constructor(form) { this.form = form; } }, TypeError});
    return {button, ignoreButton, action, ignoreAction, feedback, saved, ignored, submit: () => submit({preventDefault() {}})};
}

test('blocks duplicate requests, carries CSRF form, updates saved/ignored state and allows removal', async () => {
    let resolve;
    let calls = 0;
    const page = setup((url, options) => {
        calls++;
        assert.equal(url, '/notices/1/personal');
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.redirect, 'error');
        assert.equal(options.headers.Accept, 'application/json');
        assert.ok(options.body.form);
        return new Promise(done => { resolve = done; });
    });
    const request = page.submit();
    assert.equal(page.button.disabled, true);
    assert.equal(page.ignoreButton.disabled, true);
    await page.submit();
    assert.equal(calls, 1);
    resolve({ok: true, json: async () => ({is_saved: true, is_ignored: false, message: 'Salvată.'})});
    await request;
    assert.equal(page.action.value, 'unsave');
    assert.equal(page.saved.hidden, false);
    assert.equal(page.ignored.hidden, true);
    assert.equal(page.ignoreAction.value, 'ignore');
    assert.equal(page.button.disabled, false);
    const removal = page.submit();
    resolve({ok: true, json: async () => ({is_saved: false, is_ignored: false, message: 'Eliminată.'})});
    await removal;
    assert.equal(page.saved.hidden, true);
    assert.equal(page.action.value, 'save');
    assert.equal(page.feedback.textContent, 'Eliminată.');
});

for (const status of [401, 403, 419, 422, 500]) {
    test(`HTTP ${status} shows error without a saved state and permits retry`, async () => {
        const page = setup(async () => ({ok: false, status}));
        await page.submit();
        assert.equal(page.action.value, 'save');
        assert.equal(page.saved.hidden, true);
        assert.equal(page.button.textContent, 'Salvează');
        assert.equal(page.button.disabled, false);
        assert.ok(page.feedback.textContent);
    });
}
for (const response of [() => { throw new TypeError('offline'); }, async () => ({ok: true, json: async () => { throw new SyntaxError('HTML response'); }}), async () => ({ok: true, json: async () => ({})})]) {
    test('network or invalid response does not falsely confirm save', async () => {
        const page = setup(response);
        await page.submit();
        assert.equal(page.action.value, 'save');
        assert.equal(page.button.disabled, false);
        assert.equal(page.saved.hidden, true);
        assert.ok(page.feedback.textContent);
    });
}
