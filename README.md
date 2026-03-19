# 🚀 Nexus — Premium Stalker Portal Manager

<p align="center">
  <img src="data/logo.png" alt="Nexus Logo" width="128" height="128">
</p>

Nexus is a lightweight, high-performance Stalker Portal management system designed for simplicity, security, and advanced stream handling. It allows you to connect to any Stalker portal, sync channels, and generate an M3U playlist that can be used in any IPTV player.

---

## ✨ Key Features

- **⚡ Lightweight Engine**: Pure PHP implementation (no database required, no heavy frameworks).
- **📟 Automated Handshake**: Handles Stalker API tokens and device authentication automatically.
- **📺 Channel Synchronization**: Fetches complete channel lists including genres and logos.
- **🛡️ Advanced Stream Proxy**: 
    - **HLS Manifest Rewriting**: Routes sub-manifests and segments through your server.
    - **Smart Proxying**: Efficient, zero-buffering streaming of `.ts` segments.
    - **XOR Obfuscation**: Uses a unique, randomly generated 64-character key per installation to hide original CDN links from end-users.
- **📱 Premium UI**: Professional, mobile-friendly dashboard with glassmorphism effects and a dedicated dark-themed playlist card.
- **🔒 Security-First**:
    - **Idle Session Timeout**: Automatic logout after 5 minutes of inactivity.
    - **Protected Data**: Sensitive JSON files are protected via `.htaccess` with custom-designed error pages.
    - **Self-Hosted Assets**: Font Awesome icons are locally hosted for offline reliability and faster loading.

---

## 📂 Project Structure

```text
.
├── index.php         # Interactive Dashboard
├── login.php         # Secure Authentication & Setup
├── playlist.php      # M3U Generator & Stream Proxy Engine
├── StalkerLite.php   # Minimal Stalker API Wrapper
├── error.php         # Custom-styled Error Page (403/404/500)
├── data/             # Protected Data Directory
│   ├── portal.json   # Active Portal Configuration
│   ├── channels.json # Cached Channel Repository
│   ├── users_account.json # Credentials & Unique XOR Key
│   └── fontawesome/  # Self-hosted Icon Assets
└── .htaccess         # Security & Routing Rules
```

---

## 🚀 Getting Started

### 1. Requirements & Compatibility
- **Software**: PHP 7.4 or higher (8.x recommended) with **cURL extension**.
- **Web Servers**: Full support for **Apache**, **LiteSpeed**, and **Nginx**.
- **Environments**: Optimized for any hosting platform, including **Localhost (XAMPP)** on desktop and **KSWEB** on Android devices.
- **Protocols**: Works seamlessly on both **HTTP** and **HTTPS**.
- **Security**: Requires `.htaccess` support (Apache/LiteSpeed) for data folder protection and custom error pages out of the box. Nginx users may need equivalent configuration for these features.

### 2. Installation & One-Time Setup
1. Upload all files to your web server.
2. Ensure the `data/` directory is writable by the web server.
3. Access the folder in your browser. Since this is your first run, you will be prompted to create a **one-time admin account** (username & password) to secure your dashboard. This login is used for all future access.

### 3. Portal Connection
- **Required Fields**: You only need to enter the **Portal URL** and the **MAC Address**.
- **Automated Identifiers**: Nexus automatically calculates the **Signature**, **SN Cut**, **Device ID 1**, and **Device ID 2** based on your MAC address. 
- **Advanced Mode**: If you have specific device data you'd like to use instead, you can toggle the **Advanced Configuration** to enter them manually, but this is entirely optional.
- Click **"Connect & Fetch Playlist"** to perform the handshake and sync your channels.

---

## 📡 Usage

### Generating the Playlist
Your unique M3U URL is displayed on the dashboard. You can copy this URL or download the `.m3u` file directly.

### Using the Stream Proxy
- **Proxy ON**: All stream data flows through your server. Original CDN URLs are XOR-encrypted and hidden. This hides your portal's source IP and protects the original links.
- **Proxy OFF**: Users are redirected via a `302 Found` response directly to the CDN. This saves your server's bandwidth but exposes the original link.

### Security Notes
- **Idle Timeout**: The dashboard automatically logs you out after 5 minutes of inactivity for security.
- **Token Protection**: Access to your `.json` data files is strictly forbidden via HTTP. Any attempt to reach them is caught and redirected to a custom 403 error page.

---

## 🛠️ Technology Stack
- **Backend**: PHP 8.x
- **Frontend**: Vanilla HTML5, Modern CSS3 (Inter & JetBrains Mono fonts)
- **Icons**: Font Awesome 6.5.0 (Self-hosted)
- **Data**: JSON Flat-file storage

---

## 👨‍💻 Author
Crafted with ❤️ by **LazyyXD**

---

## 📜 License
This project is for educational purposes only. Always ensure you have the rights to access the portals you connect to.
