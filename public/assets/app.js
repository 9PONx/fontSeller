// FontSeller - simple client-side interactions
document.addEventListener('DOMContentLoaded', function () {
    initPreviewText();
    initPreviewLoader();
});

function initPreviewText() {
    var input = document.getElementById('previewText');
    var charCount = document.getElementById('charCount');
    if (!input) return;

    charCount.textContent = input.value.length;

    var debounceTimer = null;
    input.addEventListener('input', function () {
        charCount.textContent = input.value.length;
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadVisiblePreviews, 350);
    });
}

function initPreviewLoader() {
    loadVisiblePreviews();

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    loadCardPreview(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '200px' });

        document.querySelectorAll('.font-card[data-preview-status="supported"]').forEach(function (card) {
            observer.observe(card);
        });
    }
}

function loadVisiblePreviews() {
    document.querySelectorAll('.font-card[data-preview-status="supported"]').forEach(function (card) {
        var rect = card.getBoundingClientRect();
        if (rect.top < window.innerHeight + 200 && rect.bottom > -200) {
            loadCardPreview(card);
        }
    });
}

var activeRequests = {};

function loadCardPreview(card) {
    var img = card.querySelector('img[data-preview-url]');
    if (!img) return;

    var fontId = card.dataset.fontId;
    var textInput = document.getElementById('previewText');
    var text = textInput ? textInput.value : 'ทดลองฟอนต์ FontSeller 123';

    if (activeRequests[fontId]) {
        activeRequests[fontId].abort();
    }

    var controller = new AbortController();
    activeRequests[fontId] = controller;

    var url = img.dataset.previewUrl + '&text=' + encodeURIComponent(text);

    fetch(url, { signal: controller.signal })
        .then(function (response) {
            if (!response.ok) throw new Error('Preview not available');
            return response.blob();
        })
        .then(function (blob) {
            var objectUrl = URL.createObjectURL(blob);
            img.onload = function () { URL.revokeObjectURL(objectUrl); };
            img.src = objectUrl;
        })
        .catch(function (err) {
            if (err.name !== 'AbortError') {
                img.alt = 'Preview not available';
                img.classList.add('opacity-50');
            }
        })
        .finally(function () {
            delete activeRequests[fontId];
        });
}
