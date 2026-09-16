(function () {
    'use strict';

    function initialise() {
        document.querySelectorAll('[data-signature-pad]').forEach(function (pad) {
            if (pad.dataset.initialised === 'true') {
                return;
            }

            var canvas = pad.querySelector('[data-signature-canvas]');
            var value = pad.querySelector('[data-signature-value]');
            var clear = pad.querySelector('[data-signature-clear]');
            var context = canvas.getContext('2d');
            var drawing = false;
            var changed = false;
            var upload = document.getElementById(pad.dataset.uploadInputId);

            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = 5;
            context.strokeStyle = '#111';

            function point(event) {
                var rect = canvas.getBoundingClientRect();
                return {
                    x: (event.clientX - rect.left) * canvas.width / rect.width,
                    y: (event.clientY - rect.top) * canvas.height / rect.height
                };
            }

            function reset() {
                context.clearRect(0, 0, canvas.width, canvas.height);
                value.value = '';
                changed = false;
            }

            canvas.addEventListener('pointerdown', function (event) {
                var position = point(event);
                drawing = true;
                changed = true;
                canvas.setPointerCapture(event.pointerId);
                context.beginPath();
                context.moveTo(position.x, position.y);
                if (upload && upload._erpFilePond) {
                    upload._erpFilePond.removeFiles();
                } else if (upload) {
                    upload.value = '';
                }
            });

            canvas.addEventListener('pointermove', function (event) {
                if (!drawing) {
                    return;
                }
                var position = point(event);
                context.lineTo(position.x, position.y);
                context.stroke();
            });

            function finish() {
                if (!drawing) {
                    return;
                }
                drawing = false;
                if (changed) {
                    value.value = canvas.toDataURL('image/png');
                }
            }

            canvas.addEventListener('pointerup', finish);
            canvas.addEventListener('pointercancel', finish);
            clear.addEventListener('click', reset);
            if (upload) {
                upload.addEventListener('change', reset);
            }

            pad.dataset.initialised = 'true';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
}());
