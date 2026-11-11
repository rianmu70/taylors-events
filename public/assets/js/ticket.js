document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.querySelector('#ticket-qr');
    if (!canvas) return;

    if (!window.QRCode) {
        document.querySelector('#ticket-qr-error').hidden = false;
        return;
    }

    window.QRCode.toCanvas(canvas, canvas.dataset.ticketPayload, {
        width: 256,
        margin: 2,
        errorCorrectionLevel: 'M',
    }, (error) => {
        if (error) document.querySelector('#ticket-qr-error').hidden = false;
    });
});