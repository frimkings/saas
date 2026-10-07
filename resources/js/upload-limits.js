// File pickers with data-max-kb refuse oversized files in the browser, before Livewire starts
// uploading them (the server checks again: App\Support\UploadLimits). Runs in the capture phase
// so it gets the change event before Livewire's wire:model listener on the input.

const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1).replace(/\.0$/, '')} MB` : `${Math.ceil(bytes / 1024)} KB`);

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.dataset.maxKb) return;

    const maxBytes = parseInt(input.dataset.maxKb, 10) * 1024;
    const tooBig = [...(input.files || [])].filter((file) => file.size > maxBytes);
    if (!tooBig.length) return;

    event.stopImmediatePropagation();
    event.stopPropagation();
    input.value = '';

    const names = tooBig.map((file) => `${file.name} (${size(file.size)})`).join(', ');
    const message = `${tooBig.length === 1 ? 'This file is' : 'These files are'} too large: ${names}. `
        + `The limit is ${size(maxBytes)} per file. Please choose a smaller file, or a lower-resolution photo or scan.`;
    window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'error', message } }));
}, true);
