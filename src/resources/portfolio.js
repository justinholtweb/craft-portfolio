/**
 * Portfolio grid runtime — Pro.
 *
 * Two jobs, both progressive enhancements over markup that already works without them:
 *
 *   1. Filtering. Every item is rendered server-side with its category slugs on the element, and
 *      filtering hides the ones that do not match. Deciding it in the browser rather than on the
 *      server means an archive page stays a single cacheable response — the alternative is a
 *      query string per filter and a cache entry per combination.
 *   2. A lightbox over each item's gallery.
 *
 * No dependencies, no build step. Runs on DOMContentLoaded and again on demand, so grids added
 * later (htmx, Turbo, a CP preview) still get wired up.
 */
(function () {
    'use strict';

    var LIGHTBOX_ID = 'pf-lightbox';

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    // ---------------------------------------------------------------- filtering

    function wireFilters(grid) {
        var buttons = toArray(grid.querySelectorAll('[data-pf-filter]'));

        if (!buttons.length) {
            return;
        }

        var items = toArray(grid.querySelectorAll('[data-pf-item]'));

        function apply(slug) {
            var shown = 0;

            items.forEach(function (item) {
                var slugs = (item.getAttribute('data-pf-categories') || '').split(' ');
                var match = slug === '' || slugs.indexOf(slug) !== -1;

                item.hidden = !match;

                if (match) {
                    shown++;
                }
            });

            buttons.forEach(function (button) {
                button.setAttribute('aria-pressed', button.getAttribute('data-pf-filter') === slug ? 'true' : 'false');
            });

            var empty = grid.querySelector('[data-pf-empty]');

            if (empty) {
                empty.hidden = shown !== 0;
            }

            // Announced rather than silent: a filter that removes every card with no word about
            // it reads as a broken page.
            var status = grid.querySelector('[data-pf-status]');

            if (status) {
                status.textContent = shown === 1 ? '1 item' : shown + ' items';
            }
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var slug = button.getAttribute('data-pf-filter');
                apply(button.getAttribute('aria-pressed') === 'true' ? '' : slug);
            });
        });
    }

    // ---------------------------------------------------------------- lightbox

    function lightbox() {
        var existing = document.getElementById(LIGHTBOX_ID);

        if (existing) {
            return existing.pfApi;
        }

        var root = document.createElement('div');
        root.id = LIGHTBOX_ID;
        root.className = 'pf-lightbox';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Image viewer');
        root.hidden = true;

        root.innerHTML =
            '<button type="button" class="pf-lightbox__button pf-lightbox__close" aria-label="Close">×</button>' +
            '<button type="button" class="pf-lightbox__button pf-lightbox__prev" aria-label="Previous image">‹</button>' +
            '<button type="button" class="pf-lightbox__button pf-lightbox__next" aria-label="Next image">›</button>' +
            '<figure class="pf-lightbox__figure">' +
            '<img class="pf-lightbox__image" alt="">' +
            '<figcaption class="pf-lightbox__caption"></figcaption>' +
            '</figure>';

        document.body.appendChild(root);

        var image = root.querySelector('.pf-lightbox__image');
        var caption = root.querySelector('.pf-lightbox__caption');
        var prevButton = root.querySelector('.pf-lightbox__prev');
        var nextButton = root.querySelector('.pf-lightbox__next');

        var images = [];
        var index = 0;
        var lastFocused = null;

        function show(i) {
            if (!images.length) {
                return;
            }

            index = (i + images.length) % images.length;
            var current = images[index];

            image.src = current.url;
            image.alt = current.alt || '';
            caption.textContent = current.caption || '';

            var many = images.length > 1;
            prevButton.hidden = !many;
            nextButton.hidden = !many;
        }

        function open(list, start) {
            images = list;
            lastFocused = document.activeElement;
            root.hidden = false;
            document.documentElement.style.overflow = 'hidden';
            show(start || 0);
            root.querySelector('.pf-lightbox__close').focus();
        }

        function close() {
            root.hidden = true;
            image.src = '';
            document.documentElement.style.overflow = '';

            // Focus goes back where it came from, or the page loses its place entirely.
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        root.querySelector('.pf-lightbox__close').addEventListener('click', close);
        prevButton.addEventListener('click', function () { show(index - 1); });
        nextButton.addEventListener('click', function () { show(index + 1); });

        root.addEventListener('click', function (event) {
            if (event.target === root) {
                close();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (root.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                close();
            } else if (event.key === 'ArrowLeft') {
                show(index - 1);
            } else if (event.key === 'ArrowRight') {
                show(index + 1);
            } else if (event.key === 'Tab') {
                // Three buttons and nothing else: keeping Tab inside them is the whole trap.
                var focusable = toArray(root.querySelectorAll('button')).filter(function (b) { return !b.hidden; });

                if (!focusable.length) {
                    return;
                }

                var first = focusable[0];
                var last = focusable[focusable.length - 1];

                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });

        root.pfApi = { open: open, close: close };

        return root.pfApi;
    }

    function wireLightbox(grid) {
        if (grid.getAttribute('data-pf-lightbox') !== '1') {
            return;
        }

        grid.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest('[data-pf-gallery]') : null;

            if (!trigger || !grid.contains(trigger)) {
                return;
            }

            var raw = trigger.getAttribute('data-pf-gallery');
            var images;

            try {
                images = JSON.parse(raw);
            } catch (e) {
                return;
            }

            if (!images || !images.length) {
                return;
            }

            // Only take over the click once there is something to show; otherwise the card link
            // has to keep working.
            event.preventDefault();
            lightbox().open(images, 0);
        });
    }

    // ---------------------------------------------------------------- boot

    function init(scope) {
        var root = scope || document;

        toArray(root.querySelectorAll('[data-pf-grid]')).forEach(function (grid) {
            if (grid.getAttribute('data-pf-ready') === '1') {
                return;
            }

            grid.setAttribute('data-pf-ready', '1');
            wireFilters(grid);
            wireLightbox(grid);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    window.PortfolioGrid = { init: init };
}());
