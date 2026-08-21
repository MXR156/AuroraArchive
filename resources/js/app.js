const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
document.querySelector('.nav-toggle')?.addEventListener('click', () => document.querySelector('.nav-menu')?.classList.toggle('hidden'));
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
document.querySelectorAll('[data-select-failed]').forEach((button) => {
    button.addEventListener('click', () => {
        button.closest('form')?.querySelectorAll('input[name="media_ids[]"]').forEach((checkbox) => { checkbox.checked = true; });
    });
});
