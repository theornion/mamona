(function () {
    const feed = document.querySelector('[data-news-source]');

    if (!feed) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    const category = params.get('category');
    const postsPerPage = 5;
    const requestedPage = Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1);
    const source = new URL(feed.dataset.newsSource, window.location.href);
    const pageBase = feed.dataset.newsBase || '';

    if (category) {
        source.searchParams.set('category', category);
    }

    function formatDate(value) {
        const date = new Date(value.replace(' ', 'T') + 'Z');

        if (Number.isNaN(date.getTime())) {
            return '';
        }

        return new Intl.DateTimeFormat('pl-PL', {
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        }).format(date);
    }

    function withoutContentImageMarkers(value) {
        return String(value || '')
            .replace(/\[\[\s*(?:Z\d+|ZDJ[EĘ]CIE)\s*\]\]/giu, ' ')
            .replace(/[ \t]{2,}/g, ' ')
            .replace(/[ \t]+(\r?\n)/g, '$1')
            .replace(/(\r?\n)[ \t]+/g, '$1')
            .trim();
    }

    function createPost(post) {
        const article = document.createElement('a');
        article.className = 'news-feed-card news-feed-post-link';
        article.href = new URL(pageBase + post.url, window.location.href).href;

        const visual = document.createElement('div');
        visual.className = 'news-feed-visual';

        if (post.image) {
            const imageUrl = new URL(post.image, source).href;
            visual.style.backgroundImage = `url("${encodeURI(imageUrl).replace(/"/g, '%22')}")`;
            article.classList.add('has-news-feed-visual');
        }

        const content = document.createElement('div');
        content.className = 'news-feed-content';
        const categoryLabel = document.createElement('p');
        categoryLabel.className = 'news-feed-category';
        categoryLabel.textContent = post.category;
        const title = document.createElement('h2');
        title.textContent = post.title;
        const date = document.createElement('time');
        date.textContent = formatDate(post.createdAt);
        const excerpt = document.createElement('p');
        excerpt.className = 'news-feed-excerpt';
        const previewExcerpt = withoutContentImageMarkers(post.excerpt);
        const previewContent = withoutContentImageMarkers(post.content);
        excerpt.textContent = previewExcerpt || previewContent;
        const body = document.createElement('p');
        body.className = 'news-feed-body';
        body.textContent = previewContent;

        content.append(categoryLabel, title, excerpt, body, date);
        if (post.image) {
            article.appendChild(visual);
        }

        article.appendChild(content);

        return article;
    }

    function createPagination(pageCount, currentPage) {
        const pagination = document.createElement('nav');
        pagination.className = 'news-feed-pagination';
        pagination.setAttribute('aria-label', 'Strony aktualności');

        for (let page = 1; page <= pageCount; page += 1) {
            const link = document.createElement('a');
            const linkParams = new URLSearchParams(window.location.search);

            if (page === 1) {
                linkParams.delete('page');
            } else {
                linkParams.set('page', String(page));
            }

            const query = linkParams.toString();
            link.href = `${window.location.pathname}${query ? `?${query}` : ''}`;
            link.textContent = String(page);
            link.setAttribute('aria-label', `Strona ${page}`);

            if (page === currentPage) {
                link.classList.add('is-active');
                link.setAttribute('aria-current', 'page');
            }

            pagination.appendChild(link);
        }

        return pagination;
    }

    fetch(source, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Nie udało się pobrać aktualności.');
            }

            return response.json();
        })
        .then((payload) => {
            const posts = Array.isArray(payload.posts) ? payload.posts : [];
            const pageCount = Math.max(1, Math.ceil(posts.length / postsPerPage));
            const currentPage = Math.min(requestedPage, pageCount);
            const visiblePosts = posts.slice((currentPage - 1) * postsPerPage, currentPage * postsPerPage);
            const header = document.createElement('header');
            header.className = 'major news-feed-heading';
            const kicker = document.createElement('p');
            kicker.className = 'news-feed-kicker';
            kicker.textContent = 'Najnowsze';
            const heading = document.createElement('h1');
            heading.textContent = payload.category ? payload.category.title : 'Aktualności';
            const description = document.createElement('p');
            description.textContent = payload.category && payload.category.description
                ? payload.category.description
                : 'Najnowsze informacje opublikowane na stronie.';
            header.append(kicker, heading, description);

            const list = document.createElement('div');
            list.className = 'news-feed-list';

            if (visiblePosts.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'news-feed-empty';
                empty.textContent = 'W tej kategorii nie ma jeszcze opublikowanych aktualności.';
                list.appendChild(empty);
            } else {
                list.append(...visiblePosts.map(createPost));
            }

            if (posts.length > postsPerPage) {
                feed.replaceChildren(header, list, createPagination(pageCount, currentPage));
            } else {
                feed.replaceChildren(header, list);
            }
        })
        .catch((error) => {
            const message = document.createElement('p');
            message.className = 'news-feed-empty';
            message.textContent = error.message;
            feed.replaceChildren(message);
        });
}());
