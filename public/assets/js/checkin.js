document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('#checkin-camera-start');
    const video = document.querySelector('#checkin-camera-video');
    const message = document.querySelector('#checkin-camera-message');
    const form = button?.closest('form');
    const input = form?.querySelector('[name="ticket"]');
    if (!button || !video || !message || !form || !input) return;

    let stream = null;
    let scanning = false;

    const stopCamera = () => {
        scanning = false;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        video.srcObject = null;
        video.hidden = true;
        button.textContent = button.dataset.startLabel;
    };

    const scanFrame = async (detector) => {
        if (!scanning) return;
        try {
            const codes = await detector.detect(video);
            if (codes[0]?.rawValue) {
                input.value = codes[0].rawValue;
                stopCamera();
                form.requestSubmit();
                return;
            }
        } catch {
            message.textContent = message.dataset.error;
            message.hidden = false;
            stopCamera();
            return;
        }
        requestAnimationFrame(() => scanFrame(detector));
    };

    button.dataset.startLabel = button.textContent;
    button.addEventListener('click', async () => {
        if (scanning) {
            stopCamera();
            return;
        }
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            message.textContent = message.dataset.error;
            message.hidden = false;
            return;
        }

        try {
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' } },
            });
            video.srcObject = stream;
            video.hidden = false;
            await video.play();
            scanning = true;
            button.textContent = button.dataset.stopLabel;
            message.hidden = true;
            scanFrame(detector);
        } catch {
            message.textContent = message.dataset.error;
            message.hidden = false;
            stopCamera();
        }
    });

});