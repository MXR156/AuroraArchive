const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
document.querySelector('.nav-toggle')?.addEventListener('click', () => document.querySelector('.nav-menu')?.classList.toggle('hidden'));
document.querySelectorAll('[data-media-filters] select').forEach((select) => {
    select.addEventListener('change', () => select.form?.requestSubmit());
});
document.querySelectorAll('.media-player').forEach((player) => {
    const resumeAt = Number(player.dataset.resume || 0);
    player.addEventListener('loadedmetadata', () => { if (resumeAt > 0 && resumeAt < player.duration - 10) player.currentTime = resumeAt; }, { once: true });
    let lastSaved = 0;
    const save = () => {
        if (!csrfToken || Math.abs(player.currentTime-lastSaved)<10) return;
        lastSaved=player.currentTime;
        fetch(player.dataset.progressUrl,{method:'PUT',keepalive:true,headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken,'Accept':'application/json'},body:JSON.stringify({position_seconds:Math.floor(player.currentTime),watched:player.duration>0&&player.currentTime/player.duration>.9})});
    };
    player.addEventListener('timeupdate',save); player.addEventListener('pause',save);
    player.addEventListener('ended', () => {
        if (player.dataset.nextUrl) window.location.assign(player.dataset.nextUrl);
    });
});
document.querySelectorAll('[data-bulk-media-form]').forEach((form) => {
    const checkboxes = [...form.querySelectorAll('input[name="media_ids[]"]')];
    const toolbar = form.querySelector('[data-bulk-toolbar]');
    const selectedCount = form.querySelector('[data-selected-count]');
    const refreshToolbar = () => {
        const count = checkboxes.filter((checkbox) => checkbox.checked).length;
        selectedCount.textContent = count;
        toolbar.classList.toggle('hidden', count === 0);
        toolbar.classList.toggle('flex', count > 0);
    };

    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refreshToolbar));
    form.querySelector('[data-select-all]')?.addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = true; });
        refreshToolbar();
    });
    form.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = false; });
        refreshToolbar();
    });
    form.addEventListener('submit', (event) => {
        const confirmation = event.submitter?.dataset.confirm;
        if (confirmation && !window.confirm(confirmation)) {
            event.preventDefault();
        }
    });
});
