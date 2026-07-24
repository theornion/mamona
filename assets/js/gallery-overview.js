(function () {
    const overview = document.querySelector('[data-gallery-overview-source]');

    if (!overview) {
        return;
    }

    const source = new URL(overview.dataset.galleryOverviewSource, window.location.href);
    const pageBase = overview.dataset.galleryBase || '';

    function createGallery(gallery) {
        const link = document.createElement('a');
        link.className = 'news-feed-card gallery-overview-card';
        link.href = new URL(pageBase + gallery.url, window.location.href).href;

        if (gallery.image) {
            const imageUrl = new URL(gallery.image, source).href;
            link.style.backgroundImage = `linear-gradient(0deg, rgba(15, 23, 42, 0.46), rgba(15, 23, 42, 0.08)), url("${encodeURI(imageUrl).replace(/"/g, '%22')}")`;
        }
        const content = document.createElement('div');
        content.className = 'news-feed-content';
        const label = document.createElement('p');
        label.className = 'news-feed-category';
        label.textContent = 'Galeria';
        const title = document.createElement('h2');
        title.textContent = gallery.title;
        const description = document.createElement('p');
        description.className = 'news-feed-excerpt';
        description.textContent = gallery.description;
        const action = document.createElement('span');
        action.className = 'gallery-overview-action';
        action.textContent = 'Zobacz galerię';

        content.append(label, title, description, action);
        link.appendChild(content);

        return link;
    }

    fetch(source, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Nie udało się pobrać galerii.');
            }

            return response.json();
        })
        .then((payload) => {
            const galleries = Array.isArray(payload.galleries) ? payload.galleries : [];
            const header = document.createElement('header');
            header.className = 'major news-feed-heading';
            header.innerHTML = '<p class="news-feed-kicker">Zdjęcia</p><h1>Galerie</h1><p>Wybierz galerię, którą chcesz zobaczyć.</p>';
            const list = document.createElement('div');
            list.className = 'news-feed-list';

            if (galleries.length === 0) {
                list.innerHTML = '<p class="news-feed-empty">Nie ma jeszcze żadnych galerii.</p>';
            } else {
                list.append(...galleries.map(createGallery));
            }

            overview.replaceChildren(header, list);
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'news-feed-empty';
            message.textContent = error.message;
            overview.replaceChildren(message);
        });
}());
