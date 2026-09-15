//

const panel = document.getElementById('manual-scan');
if (panel) {
    const form = document.getElementById('manual-scan-form');
    const button = document.getElementById('manual-scan-button');
    const probeButton = document.getElementById('manual-scan-probe');
    const resumeButton = document.getElementById('manual-scan-resume');
    const status = document.getElementById('manual-scan-status');
    const results = document.getElementById('manual-scan-results');
    const refresh = document.getElementById('manual-scan-refresh');
    const labels = {cancelled: 'Anulată', queued: 'În așteptare', pending: 'În așteptare', running: 'În curs', completed: 'Finalizată', partial: 'Finalizată parțial', failed: 'Cu erori', error: 'Cu erori', not_run: 'Neexecutată', unverified: 'Acoperire neconfirmată', sample_ok: 'Import limitat reușit', probe_ok: 'API răspunde (test limitat)', tested: 'Test limitat reușit'};
    let timer;
    let requestVersion = 0;
    const line = (text, error = false) => {
        const element = document.createElement('p');
        element.textContent = text;
        if (error) element.className = 'text-red-700';
        results.appendChild(element);
    };
    function render(scan) {
        clearTimeout(timer);
        if (!scan) return;
        const active = ['queued', 'running'].includes(scan.status);
        button.disabled = active;
        probeButton.disabled = active;
        resumeButton.hidden = !scan.can_resume;
        resumeButton.value = scan.id;
        button.textContent = active ? 'Scanare în curs…' : 'Scanează acum';
        status.textContent = `Ultima scanare: ${scan.started_at || scan.created_at} — ${labels[scan.status]}. Perioadă verificată: ${scan.from} – ${scan.to}.`;
        if (scan.finished_at) status.textContent += ` Încheiată la ${scan.finished_at}.`;
        results.replaceChildren();
        line(`${scan.counts.new} noi · ${scan.counts.updated} actualizate · ${scan.counts.checked} verificate`);
        for (const [source, result] of Object.entries(scan.results)) {
            line(`${source.toUpperCase()}: ${labels[result.status]} — ${result.new} noi, ${result.updated} actualizate, ${result.unchanged} fără modificări.`);
            if (source === 'seap') {
                for (const family of ['PI', 'CN', 'SCN']) {
                    const stats = result.families?.[family];
                    line(`${family}: ${stats?.processed ?? '—'} verificate · ${labels[stats?.status] || labels.unverified} · ${stats?.fetched ?? '—'} preluate / ${stats?.reported ?? '—'} raportate · ${stats?.new ?? 0} noi · ${stats?.updated ?? 0} actualizate · ${stats?.duplicates ?? 0} duplicate`);
                    if (stats?.refresh_day) line(`${family}: reverificare anunțuri mai vechi din ${stats.refresh_day}.`);
                    for (const error of stats?.errors || []) line(`${family}: ${error}`, true);
                }
                line(`Total SEAP: ${result.checked} verificate`);
                if (Object.values(result.families || {}).some(stats => stats.status === 'unverified')) {
                    line('Lipsește confirmarea executării unei familii. Raportul vechi nu dovedește acoperirea PI/CN/SCN; verificați versiunea procesului de fundal.', true);
                }
            }
            if (result.error_count) line(`Erori ${source.toUpperCase()}: ${result.error_count}`, true);
            for (const error of result.errors || []) line(error, true);
        }
        if (scan.message) line(scan.message);
        if (scan.waiting) line('Scanarea așteaptă procesul de fundal. Dacă starea persistă, procesul de scanare trebuie repornit.', true);
        refresh.hidden = active;
        if (active) timer = setTimeout(poll, 2500);
    }
    async function poll() {
        const version = requestVersion;
        try {
            const response = await fetch(panel.dataset.statusUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error();
            const scan = await response.json();
            if (version !== requestVersion) return;
            render(scan);
        } catch {
            if (version !== requestVersion) return;
            status.textContent = 'Nu pot verifica momentan starea. Reîncerc automat; scanarea poate continua în fundal.';
            timer = setTimeout(poll, 5000);
        }
    }
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        requestVersion++;
        clearTimeout(timer);
        results.replaceChildren();
        refresh.hidden = true;
        button.disabled = true;
        probeButton.disabled = true;
        status.textContent = 'Pornesc scanarea…';
        try {
            const data = new FormData(form);
            if (event.submitter?.name === 'resume_id') {
                data.set('resume_id', event.submitter.value);
                data.delete('from');
                data.delete('to');
            } else {
                data.set('mode', event.submitter?.value || 'full');
            }
            const response = await fetch(form.action, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
            if (response.status === 422) {
                const invalid = await response.json();
                status.textContent = Object.values(invalid.errors || {}).flat().join(' ');
                button.disabled = false;
                probeButton.disabled = false;
                return;
            }
            if (!response.ok) throw new Error();
            render(await response.json());
        } catch {
            status.textContent = 'Pornirea nu a putut fi confirmată. Verific starea scanării…';
            button.disabled = false;
            probeButton.disabled = false;
            timer = setTimeout(poll, 1000);
        }
    });
    poll();
}
