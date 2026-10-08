for (const form of document.querySelectorAll('[data-save-notice]')) {
    const card = form.closest('article');
    const button = form.querySelector('button');
    const action = form.querySelector('[name="action"]');
    const feedback = card.querySelector('[data-save-feedback]');
    const ignoreForm = card.querySelector('form:not([data-save-notice])');
    let pending = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending) return;
        pending = true;
        const previousLabel = button.textContent;
        const buttons = [...card.querySelectorAll('form button')];
        const disabledStates = buttons.map(element => element.disabled);
        buttons.forEach(element => { element.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        button.textContent = 'Se procesează…';
        feedback.textContent = '';
        feedback.classList.remove('text-red-700');

        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'error',
                headers: {Accept: 'application/json'},
            });
            if (!response.ok) {
                throw new Error([401, 419].includes(response.status)
                    ? 'Sesiunea a expirat. Reautentifică-te înainte să reîncerci.'
                    : 'Modificarea nu a putut fi confirmată. Reîncearcă.');
            }
            const state = await response.json();
            if (typeof state.is_saved !== 'boolean' || typeof state.is_ignored !== 'boolean' || typeof state.message !== 'string') {
                throw new Error('Răspuns neașteptat. Modificarea nu a putut fi confirmată.');
            }
            action.value = state.is_saved ? 'unsave' : 'save';
            button.textContent = state.is_saved ? 'Elimină din salvate' : 'Salvează';
            button.setAttribute('aria-label', `${button.textContent}: ${form.dataset.noticeTitle}`);
            card.querySelector('[data-saved-badge]').hidden = !state.is_saved;
            card.querySelector('[data-ignored-badge]').hidden = !state.is_ignored;
            if (ignoreForm) {
                ignoreForm.querySelector('[name="action"]').value = state.is_ignored ? 'restore' : 'ignore';
                const ignoreButton = ignoreForm.querySelector('button');
                ignoreButton.textContent = state.is_ignored ? 'Nu mai ignora' : 'Ignoră';
                ignoreButton.setAttribute('aria-label', `${ignoreButton.textContent}: ${form.dataset.noticeTitle}`);
            }
            feedback.textContent = state.message;
        } catch (error) {
            button.textContent = previousLabel;
            feedback.textContent = error instanceof TypeError
                ? 'Conexiunea a eșuat. Modificarea nu a putut fi confirmată. Reîncearcă.'
                : error.message;
            feedback.classList.add('text-red-700');
        } finally {
            pending = false;
            buttons.forEach((element, index) => { element.disabled = disabledStates[index]; });
            form.removeAttribute('aria-busy');
        }
    });
}
