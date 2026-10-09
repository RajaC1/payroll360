# Payroll360 — full-page SharePoint app (SPFx)

This is a minimal SharePoint Framework (SPFx) web part that wraps Payroll360
(`https://www.appz360.com/payroll360-app/`) in a full-bleed iframe, built so it can
run on a SharePoint **Single Part App Page** — the page layout where SharePoint
hides its own header/nav and lets one web part fill the whole screen.

## 1. Before you start: Node version matters

SPFx's build tool (`gulp`) only works on specific Node.js versions — **not** whatever
you may already have installed (this project was scaffolded against SPFx 1.18.2, which
needs Node 16.13–16.x or 18.17.1–18.x; it will fail or behave unpredictably on Node 20+,
including Node 24). Check what you have:

```
node --version
```

If it isn't 16.13–16.x or 18.17.1–18.x, install [nvm-windows](https://github.com/coreybutler/nvm-windows)
and switch:

```
nvm install 18.20.4
nvm use 18.20.4
```

## 2. Install dependencies

From this folder:

```
npm install
```

## 3. Build and package

```
npm run build
npm run package
```

This produces `solution/payroll360-spfx.sppkg` — the single file you upload to SharePoint.

## 4. Upload to the App Catalog

You need **SharePoint admin** access for this step.

1. Go to your tenant's **App Catalog** site (`Admin Center → More features → Apps → App Catalog`,
   or `https://<tenant>.sharepoint.com/sites/apps` if one already exists).
2. Open **Apps for SharePoint** and drag `payroll360-spfx.sppkg` in.
3. When prompted, trust the solution ("Make this solution available to all sites in the
   organization" if you want every site to be able to use it — you can also scope it
   to specific sites via **Site Collection App Catalog** instead of the tenant-wide one).

## 5. Add the app to your site

1. On the SharePoint site where you want Payroll360, go to **Site contents → New → App**.
2. Find **Payroll360** in the list and add it.

## 6. Create the full-page app page

This is the step that actually gives you a full-page experience with no SharePoint chrome:

1. **Site contents → New → App Page** (not "Site Page" — App Page is the Single Part
   App Page type).
2. Pick **Payroll360** from the list of available app parts.
3. SharePoint creates a page with Payroll360 as its only web part — because the
   manifest declares `"supportedHosts": [..., "SharePointFullPage"]`, SharePoint
   recognizes it can run full-bleed and strips its header, nav and footer automatically.
4. Name and publish the page.

If you don't see "App Page" as an option, your site may not support Single Part App
Pages (Communication/Team sites generally do; some restricted templates don't) — fall
back to a regular page with a full-width section, as described in the embed guide.

## 7. Link to it from navigation

Add the new app page to your site's left nav or Quick Launch so people can find it —
it's just another page URL once created.

## Changing the Payroll360 URL

The web part has one configurable property (**Payroll360 URL**, in its property pane) —
useful if you ever point this at a staging environment instead of production. Edit the
page, select the web part, and use the edit (pencil) icon.

## What this does NOT give you

- **Single sign-on.** This still opens the Payroll360 sign-in screen inside the frame;
  it does not pass through the visitor's Microsoft 365 identity. Set up Microsoft Entra ID
  SSO from Payroll360's own Settings → Integration page separately if you want that.
- **Native SharePoint theming.** The app keeps its own look; it isn't restyled to match
  your SharePoint theme.
