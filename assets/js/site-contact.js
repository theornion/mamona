(function () {
    const socialLists = Array.from(document.querySelectorAll('#nav > ul.icons, #footer ul.icons.alt'));

    socialLists.forEach((list) => list.replaceChildren());

    function setAddress(settings) {
        const element = document.querySelector('[data-contact-field="address"] p');
        const section = element && element.closest('[data-contact-field="address"]');

        if (element && settings.address) {
            element.replaceChildren(...settings.address.split(/\r?\n/).flatMap((line, index) => {
                return index === 0 ? [document.createTextNode(line)] : [document.createElement('br'), document.createTextNode(line)];
            }));
            if (section) section.hidden = false;
        }
    }

    function setPhone(settings) {
        const link = document.querySelector('[data-contact-field="phone"] a');
        const section = link && link.closest('[data-contact-field="phone"]');

        if (link && settings.phone) {
            link.textContent = settings.phone;
            link.href = 'tel:' + settings.phone.replace(/[^+\d]/g, '');
            if (section) section.hidden = false;
        }
    }

    function setEmail(settings) {
        const link = document.querySelector('[data-contact-field="email"] a');
        const section = link && link.closest('[data-contact-field="email"]');

        if (link && settings.email) {
            link.textContent = settings.email;
            link.href = 'mailto:' + settings.email;
            if (section) section.hidden = false;
        }
    }

    function setSiteIdentity(settings) {
        const siteName = String(settings.site_name || '').trim();
        const tagline = String(settings.site_tagline || '').trim();
        const copyright = String(settings.copyright_text || '').trim();

        if (siteName) {
            document.querySelectorAll('[data-site-name]').forEach((element) => {
                element.textContent = siteName;
            });
            document.title = document.title.replace('Twoja marka', siteName);
        }
        if (tagline) {
            document.querySelectorAll('[data-site-tagline]').forEach((element) => {
                element.textContent = tagline;
            });
        }
        if (copyright) {
            document.querySelectorAll('[data-site-copyright]').forEach((element) => {
                element.textContent = copyright;
            });
        }
        document.querySelectorAll('[data-site-year]').forEach((element) => {
            element.textContent = String(new Date().getFullYear());
        });
    }

    function createSocialItem(social, isFooter) {
        const item = document.createElement('li');
        const link = document.createElement(social.url ? 'a' : 'span');
        const label = document.createElement('span');

        if (social.url) {
            link.href = social.url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        }
        link.className = 'icon social-media-link' + (isFooter ? ' alt' : '');
        link.setAttribute('aria-label', social.name);
        label.className = 'label';
        label.textContent = social.name;

        if (social.icon_path) {
            const image = document.createElement('img');
            image.src = social.icon_path;
            image.alt = '';
            link.classList.add('has-custom-icon');
            link.append(image, label);
        } else {
            link.classList.add('brands', social.icon_class);
            link.appendChild(label);
        }

        item.appendChild(link);
        return item;
    }

    function setSocialMedia(settings) {
        const socialMedia = Array.isArray(settings.social_media) ? settings.social_media : [];
        const socialSection = document.querySelector('[data-social-section]');

        socialLists.forEach((list) => {
            const isFooter = Boolean(list.closest('#footer'));
            list.replaceChildren(...socialMedia.map((social) => createSocialItem(social, isFooter)));
        });
        if (socialSection) socialSection.hidden = socialMedia.length === 0;
    }

    const settingsPath = window.location.pathname.includes('/pages/')
        ? '../php/contact-settings.php'
        : window.location.pathname.includes('/php/')
            ? 'contact-settings.php'
            : 'php/contact-settings.php';

    fetch(settingsPath, { cache: 'no-store', headers: { Accept: 'application/json' } })
        .then((response) => response.ok ? response.json() : Promise.reject())
        .then((settings) => {
            setSiteIdentity(settings);
            setAddress(settings);
            setPhone(settings);
            setEmail(settings);
            setSocialMedia(settings);
        })
        .catch(() => {});
}());
