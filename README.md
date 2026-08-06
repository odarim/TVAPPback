# TVAPP Project Setup Guide

Welcome to the TVAPP project! This application is split into a **Backend** built with Symfony (PHP) and a **Frontend** built with React + Vite. Follow these step-by-step instructions after your first clone to get both environments up and running on your local machine.

---

## 📋 Prerequisites

Before you begin, ensure you have the following installed on your machine:

- **PHP 8.1 or higher**
- **Composer** (PHP dependency manager)
- **Symfony CLI**
- **Node.js** (v18+) and **Yarn**
- **PostgreSQL** (running locally or via Docker)

---

## 🛠️ Step 1: Backend Setup (`TVAPPback`)

The backend is an API platform backend built in Symfony.

1. **Navigate to the Backend Directory:**
   ```bash
   cd TVAPPback
   ```

2. **Install PHP Dependencies:**
   Use Composer to install all required vendor packages.
   ```bash
   composer install
   ```

3. **Configure Environment Variables:**
   - Create a local environment file to securely store your local configuration overrides without committing them:
     ```bash
     cp .env .env.local
     ```
     *(On Windows CMD/Powershell, you can simply duplicate and rename `.env` to `.env.local`)*
   - Open `.env.local` and ensure your `DATABASE_URL` contains the correct PostgreSQL credentials for your local database. For example:
     ```env
     DATABASE_URL="postgresql://postgres:kazuto@127.0.0.1:8000/tv_streaming?serverVersion=15&charset=utf8"
     ```
   - *(Optional)*: If you are using Docker, you can start the included postgres container:
     ```bash
     docker compose up -d
     ```

4. **Initialize & Update Database:**
   Update your database schema forcefully based on the current entities.
   ```bash
   symfony console d:s:u -f
   ```

5. **Generate JWT Keys for Authentication:**
   Generate the public and private keys needed for JWT token generation:
   ```bash
   symfony console lexik:jwt:generate-keypair --overwrite
   ```

6. **Seed the Database with Sample Data:**
   Run the custom seeding command to populate initial data (like categories, movies, etc.):
   ```bash
   symfony console app:seed-data
   ```

6. **Start the Symfony Dev Server:**
   ```bash
   symfony serve
   ```
   *The backend API will run on the port given by Symfony (typically `https://127.0.0.1:8000`). Leave this terminal window running.*

---

## 💻 Step 2: Frontend Setup (`TVAPPfront`)

The frontend is a fast and modern React application served via Vite.

1. **Open a New Terminal and Navigate to the Frontend Directory:**
   In a new terminal window / tab:
   ```bash
   cd TVAPPfront
   ```

2. **Install Frontend Dependencies:**
   Download and install all React + Vite packages.
   ```bash
   yarn install
   ```
   *(If you don't have yarn installed, you can use `npm install`)*

3. **Start the Frontend Dev Server:**
   ```bash
   yarn run dev
   ```

4. **Access the Web App:**
   Open your browser at the URL provided by Vite in your terminal (typically `http://localhost:5173`).

---

## 🔄 Daily Development Workflow

When everything is successfully set up, starting your development environment every day requires just two commands in separate terminal windows:

- **Backend:** 
  ```bash
  cd TVAPPback
  symfony serve
  ```
- **Frontend:** 
  ```bash
  cd TVAPPfront
  yarn run dev
  ```

---

## 📡 IPTV Stream Validation (Detecting Geo-Blocks)

Due to copyright limitations, free IPTV `.m3u8` feeds are frequently bound by geo-restrictions. We've included an automated PHP checker tool (`iptv-checker.php`) in the backend repository to help you analyze your stream lists before importing them into the ecosystem.

### How to use the Checker tool

1. **Format your input**: The tool expects a JSON file containing an array of channels (for example, `all-channels.json`) where each channel has the `name` and `iptv_urls` array containing the streams.
2. **Execute the script**:
   ```bash
   php iptv-checker.php all-channels.json results.json
   ```
3. The script will ping each stream using a faster `HEAD` request without downloading video data, and flag the exact endpoint status.

### Automated Geo-Block Detection Strategy

To definitively detect varying types of URL restrictions, you must cross-reference two test passes:

1. **Pass #1**: Run the script over your local internet connection (No VPN). Save output as `results-local.json`.
2. **Pass #2**: Connect your computer to a **VPN** (e.g., matching the country of origin of the target channels). Re-run the script. Save output as `results-vpn.json`.

**Compare the results to conclude the stream status:**

| Comparison Result | Meaning | Next Steps / How to Proceed |
| :--- | :--- | :--- |
| **Works only with VPN** | 🔒 **Geo-blocked** | Do not import as public feeds unless your platform proxies the video chunks. Label as "Geo-Blocked" in your admin dashboard. |
| **Works everywhere** | 🌍 **Public** | Perfect candidate for the master catalog. Import and mark as Active immediately. |
| **403 everywhere** | 🚫 **Restricted** | IP is blacklisted, User-Agent is blocked, or token is required. Attempt modifying the stream URL to include token hashes. |
| **Timeout / dead** | ⚠️ **Unstable** | Server is offline. Drop these from your import file completely. |
