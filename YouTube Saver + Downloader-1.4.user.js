// ==UserScript==
// @name         YouTube Saver + Downloader
// @namespace    http://tampermonkey.net/
// @version      1.4
// @description  Adds Save and Download buttons directly on the video (watch, shorts, embeds)
// @author       Sarfraz
// @match        *://*.youtube.com/*
// @exclude      about:*
// @exclude      chrome:*
// @exclude      chrome-extension://*
// @exclude      edge:*
// @exclude      moz-extension://*
// @icon         https://www.youtube.com/favicon.ico
// @grant        none
// @run-at       document-idle
// ==/UserScript==

(function () {
    'use strict';

    const SAVE_ENDPOINT_BASE = 'https://yt-bookmarker.test/bookmarklet.php?url=';
    const DOWNLOAD_ENDPOINT_BASE = 'http://localhost/youtube_downloader/index.php?url=';

    const once = (fn) => { let done = false; return (...a) => { if (done) return; done = true; return fn(...a); }; };
    const isShorts = () => location.pathname.startsWith('/shorts/');
    const isEmbed  = () => location.pathname.startsWith('/embed/');
    const isWatch  = () => location.pathname === '/watch';
    const isYouTubeHost = () => /(^|\.)youtube\.com$/i.test(location.hostname);

    const getCanonicalWatchUrl = () => {
        const url = new URL(location.href);
        if (isWatch() && url.searchParams.get('v')) return `https://www.youtube.com/watch?v=${url.searchParams.get('v')}`;
        if (isShorts()) { const id = url.pathname.split('/')[2]; if (id) return `https://www.youtube.com/watch?v=${id}`; }
        if (isEmbed())  { const id = url.pathname.split('/')[2]?.split('?')[0]; if (id) return `https://www.youtube.com/watch?v=${id}`; }
        return url.href;
    };

    const getPlayerContainer = () => {
        return (
            document.querySelector('.html5-video-player') ||
            document.getElementById('movie_player') ||
            document.querySelector('ytd-player #container') ||
            document.querySelector('ytd-reel-video-renderer .html5-video-player') ||
            document.querySelector('video')?.parentElement ||
            null
        );
    };

    const isVideoContext = () => isYouTubeHost() && (isWatch() || isShorts() || isEmbed() || !!getPlayerContainer()?.querySelector('video'));

    const ensureStyle = once(() => {
        const css = `
      .yt-inplayer-btns {
        position: absolute;
        right: 10px;
        bottom: 58px;              /* adjust if controls overlap */
        display: flex;
        gap: 8px;
        align-items: center;
        z-index: 2147483647;
        pointer-events: none;      /* only buttons catch clicks */
      }
      .yt-inplayer-btns.is-shorts { bottom: 16px; right: 12px; }

      .yt-inplayer-btn {
        pointer-events: auto;
        background: rgba(0,0,0,0.45);
        color: #fff;
        border: 1px solid rgba(255,255,255,0.28);
        border-radius: 10px;
        padding: 6px 8px;
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
        box-shadow: 0 6px 18px rgba(0,0,0,0.25);
        transition: transform .08s ease, background .15s ease, opacity .2s ease;
        user-select: none;
      }
      .yt-inplayer-btn:hover { transform: translateY(-1px); background: rgba(0,0,0,0.6); }

      /* fade when YT autohides controls; set to 0 if you want full hide */
      .html5-video-player.ytp-autohide .yt-inplayer-btns { opacity: 0.25; }
      .html5-video-player:hover .yt-inplayer-btns { opacity: 1; }
    `;
        const style = document.createElement('style');
        style.textContent = css;
        document.documentElement.appendChild(style);
    });

    // Create or fetch the per-player group (no global IDs)
    const ensureGroup = (player) => {
        let group = player.querySelector(':scope > .yt-inplayer-btns');
        if (!group) {
            group = document.createElement('div');
            group.className = 'yt-inplayer-btns';
            const cs = getComputedStyle(player);
            if (cs.position === 'static') player.style.position = 'relative';
            player.appendChild(group);
        }
        group.classList.toggle('is-shorts', isShorts());
        return group;
    };

    // Find a child button by role inside the group
    const queryBtn = (group, role) => group.querySelector(`.yt-inplayer-btn[data-role="${role}"]`);

    const upsertBtn = (group, role, label, title, handler) => {
        let btn = queryBtn(group, role);
        if (!btn) {
            btn = document.createElement('button');
            btn.className = 'yt-inplayer-btn';
            btn.setAttribute('data-role', role);
            group.appendChild(btn);
        }
        btn.textContent = label;
        btn.title = title;
        btn.onclick = handler;
    };

    const removeAllGroups = () => document.querySelectorAll('.yt-inplayer-btns').forEach(el => el.remove());

    const render = () => {
        if (!isVideoContext()) { removeAllGroups(); return; }
        ensureStyle();
        const player = getPlayerContainer();
        if (!player) return;

        const group = ensureGroup(player);
        const url = () => encodeURIComponent(getCanonicalWatchUrl());

        upsertBtn(group, 'save', '💾', 'Save', () => window.open(SAVE_ENDPOINT_BASE + url(), '_blank', 'noopener'));
        upsertBtn(group, 'download', '📥', 'Download', () => window.open(DOWNLOAD_ENDPOINT_BASE + url(), '_blank', 'noopener'));
    };

    // Observe SPA + DOM
    let rafPending = false;
    const schedule = () => { if (rafPending) return; rafPending = true; requestAnimationFrame(() => { rafPending = false; render(); }); };

    const observer = new MutationObserver(schedule);

    const start = () => {
        render();
        observer.observe(document.documentElement, { childList: true, subtree: true });

        window.addEventListener('yt-navigate-finish', schedule, { passive: true });

        const _ps = history.pushState, _rs = history.replaceState;
        const tick = () => setTimeout(schedule, 0);
        history.pushState = function () { _ps.apply(this, arguments); tick(); };
        history.replaceState = function () { _rs.apply(this, arguments); tick(); };
        window.addEventListener('popstate', schedule, { passive: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true, passive: true });
    } else {
        start();
    }

    window.addEventListener('beforeunload', () => observer.disconnect(), { passive: true });
})();
