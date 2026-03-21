# Base Fare Architecture Overview

This document clearly defines the separation between the **Marketing Website** and the **CRM System**. These are two independent applications with different tech stacks, different deployment targets, and separate codebases.

---

## 🏗️ 1. The Marketing Website (`basefare b2b`)
This is the public-facing lead generation and consumer information site.

- **Repository:** `basefare b2b`
- **Tech Stack:** Static HTML, Vanilla CSS/JS, Vite build system
- **Hosting Target:** Vercel (or similar CDN/static host)
- **Primary Function:** SEO, generating inbound leads, displaying company policies, providing contact forms.
- **Database:** None (static site). Forms may submit to external APIs or email endpoints.
- **Git Strategy:** Changes here trigger Vercel builds.

---

## 🔒 2. The Internal CRM System (`basefare-crm`)
This is the secure internal operational portal for Base Fare agents and admins.

- **Repository:** `basefare-crm` (this repository)
- **Tech Stack:** PHP 8.x (Slim 4 + Eloquent ORM), MySQL 8.x, Tailwind CSS
- **Hosting Target:** Hostinger (Shared Hosting)
- **Primary Function:** Agent attendance tracking, financial transaction recording, payroll calculation, boarding pass notifications, and secure card vaulting.
- **Database:** MySQL relational database.
- **Git Strategy:** `main` branch auto-deploys to Hostinger.

---

## ⚠️ Anti-Clash Rules
To ensure absolutely no conflicts or errors between the two environments:

1. **Never commit `.env` files.** Environment variables for the CRM must be configured directly on Hostinger, and stored locally in a gitignored `.env`.
2. **Never mix dependencies.** Node modules and `package.json` belong to the website. PHP `vendor` and `composer.json` belong to the CRM.
3. **No shared databases.** The marketing site should never connect directly to the CRM database.
4. **Separate Deployments.** Pushing to the marketing repo will not affect the CRM. Pushing to the CRM repo will not affect the marketing site.

---

## 🌐 Live DNS Configuration (Important)
Because `base-fare.com` DNS records are hosted externally (Vercel/Namecheap/Cloudflare), creating the subdomain inside Hostinger only creates the folder — it does **not** route internet traffic there automatically.

**When the CRM is ready to go live, you MUST do this:**
1. Log into your external DNS provider (wherever `base-fare.com` nameservers point).
2. Create a new **A Record**.
3. Set the **Host/Name** to `crm`.
4. Set the **Value/IP Address** to your Hostinger server's IP address (found in your Hostinger dashboard under "Hosting Details").
5. Save changes and wait for propagation.
