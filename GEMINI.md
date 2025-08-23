# GEMINI.md - Youtube Downloader

## Project Overview

This project is a web-based YouTube video downloader. It consists of a PHP backend that uses the `yt-dlp.exe` command-line tool to fetch video information and perform downloads. The frontend is a single `index.php` file with HTML, CSS, and JavaScript, providing a user interface to enter a YouTube URL, select video quality, and initiate downloads.

The project also includes a Tampermonkey user script (`YouTube Saver + Downloader-1.4.user.js`) that injects "Save" and "Download" buttons directly into the YouTube video player interface in a web browser. The "Download" button redirects to the `index.php` page with the video URL pre-filled.

## Key Technologies

*   **Backend:** PHP
*   **Frontend:** HTML, CSS, JavaScript
*   **Core Downloader:** `yt-dlp.exe` (a fork of youtube-dl)
*   **Browser Integration:** Tampermonkey User Script (JavaScript)

## Building and Running

This is a PHP web application. To run it, you need a web server with PHP support (like Apache or Nginx) and the `yt-dlp.exe` executable in the same directory as `index.php`.

1.  **Web Server:**
    *   Place the project files in the document root of your web server.
    *   Access the `index.php` file in your web browser (e.g., `http://localhost/youtube_downloader/`).

2.  **Dependencies:**
    *   The project relies on the `yt-dlp.exe` executable being present in the project root.
    *   The PHP backend uses `shell_exec()` and `proc_open()` to execute `yt-dlp.exe`. Ensure these functions are not disabled in your PHP configuration.

3.  **Tampermonkey Script:**
    *   To use the browser integration, you need the Tampermonkey browser extension (or a similar user script manager).
    *   Install the `YouTube Saver + Downloader-1.4.user.js` script in Tampermonkey.
    *   The script is configured to point to `http://localhost/youtube_downloader/index.php`. If you are running the project on a different URL, you will need to update the `DOWNLOAD_ENDPOINT_BASE` constant in the user script.

## Development Conventions

*   The backend logic and frontend UI are combined in a single `index.php` file.
*   Backend operations are handled through AJAX requests to the same `index.php` file.
*   The `yt-dlp.exe` tool is used for all interactions with YouTube.
*   The Tampermonkey script is written in modern JavaScript and is designed to work with YouTube's dynamic page structure.
*	See "ytdlp_output.log" file in case you need to see error log for `yt-dlp.exe`. 
