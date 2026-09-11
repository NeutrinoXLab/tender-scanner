//

const panel = document.getElementById('manual-scan');
if (panel) {
    const form = document.getElementById('manual-scan-form');
    const button = document.getElementById('manual-scan-button');
    const status = document.getElementById('manual-scan-status');
    const results = document.getElementById('manual-scan-results');
    const refresh = document.getElementById('manual-scan-refresh');
    const labels = {queued: 'În așteptare', pending: 'În așteptare', running: 'În curs', completed: 'Finalizată', partial: 'Finalizată parțial', failed: 'Cu erori', error: 'Cu erori'};
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
        button.textContent = active ? 'Scanare în curs…' : 'Scanează acum';
        status.textContent = `Ultima scanare: ${scan.started_at || scan.created_at} — ${labels[scan.status]}. Perioadă verificată: ${scan.from} – ${scan.to}.`;
        if (scan.finished_at) status.textContent += ` Încheiată la ${scan.finished_at}.`;
        results.replaceChildren();
        line(`${scan.counts.new} noi · ${scan.counts.updated} actualizate · ${scan.counts.checked} verificate`);
        for (const [source, result] of Object.entries(scan.results)) {
            line(`${source.toUpperCase()}: ${labels[result.status]} — ${result.new} noi, ${result.updated} actualizate, ${result.unchanged} fără modificări.`);
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
        status.textContent = 'Pornesc scanarea…';
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error();
            render(await response.json());
        } catch {
            status.textContent = 'Pornirea nu a putut fi confirmată. Verific starea scanării…';
            button.disabled = false;
            timer = setTimeout(poll, 1000);
        }
    });
    poll();
}
