<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Idempotent (firstOrCreate) so it never overwrites content the admin has
 * already edited through /admin/pages - only fills in the row the first
 * time it's missing.
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        Page::firstOrCreate(
            ['slug' => 'privacy'],
            ['title' => 'Privacy Policy', 'content' => $this->privacyContent()]
        );
    }

    private function privacyContent(): string
    {
        return <<<'HTML'
<p><em>Last updated: September 2026</em></p>

<p>This policy explains what information SpeedPilot ("the app", "we") collects when a
merchant installs it on a Shopify store, why we collect it, and how it's handled. It
applies to the SpeedPilot Shopify app and its public website.</p>

<h2>Information we collect</h2>

<h3>Store and account information</h3>
<p>When you install SpeedPilot, Shopify provides us with your store's domain, an access
token that lets the app act on your behalf within the permissions you approve, and the
list of permissions (scopes) granted. We also store your selected plan and billing
status. Your access token is encrypted at rest and is never shared with any third
party.</p>

<h3>Performance scan data</h3>
<p>When you run a scan, SpeedPilot loads pages from your live storefront (or a
theme-preview URL you've selected) using an automated browser, the same way a real
visitor's browser would. From that scan we store: performance metrics (load times,
Core Web Vitals), a list of detected issues, a small screenshot of each scanned page,
and - where relevant to a fix - the contents of specific theme files SpeedPilot reads
or edits, along with a backup of the original content so any change can be rolled back.</p>

<h3>Real-visitor performance data</h3>
<p>If you enable SpeedPilot's optional storefront extension, it collects Core Web
Vitals measurements (load time, responsiveness, visual stability) from real visitors'
browsers as they use your store, so you can see field data alongside lab scans. This
collection is limited to the page URL and the performance numbers themselves - it does
not collect names, email addresses, IP addresses, device identifiers, or any other
information that identifies a specific visitor.</p>

<h3>Information you choose to provide</h3>
<p>Some SpeedPilot features are optional and only store data if you turn them on: a
storefront password (if your store is password-protected and you want scans to be able
to reach it), a target theme selection, scan frequency/device preferences, and a Slack
webhook URL (if you want notifications). Anything you provide here is encrypted at rest
where it could be used to access your store or another service.</p>

<h2>How we use this information</h2>
<p>We use the information above solely to operate the app: running the scans you
request or schedule, diagnosing performance issues, applying the fixes you approve,
keeping backups so changes are reversible, showing you before/after results, and
sending the notifications you've configured. We do not sell store data, and we do not
use it for advertising.</p>

<h2>Third-party services</h2>
<p>SpeedPilot shares a limited amount of data with the following services, only as
needed to provide the features described above:</p>
<ul>
<li><strong>Shopify</strong> - the platform SpeedPilot is built on; all store access
goes through Shopify's own APIs and permission system.</li>
<li><strong>Anthropic (Claude API)</strong> - when you request an AI-generated
recommendation or prioritization for detected issues, the issue's category, title, and
description are sent to Anthropic to generate that text. No customer or visitor data is
included in these requests.</li>
<li><strong>Google PageSpeed Insights</strong> - if configured, a scanned page's public
URL may be sent to Google's PageSpeed Insights API as a secondary, on-demand
performance check.</li>
<li><strong>Slack</strong> - if you connect a Slack webhook, SpeedPilot sends
notification messages to that webhook. This goes only to the Slack workspace you
configured; we have no access to your Slack workspace beyond that one webhook URL.</li>
</ul>

<h2>Data storage and security</h2>
<p>Data is stored in a Postgres database on Railway's hosting infrastructure. Sensitive
fields - your Shopify access token, storefront password, and Slack webhook URL - are
encrypted at rest. All traffic to and from the app is encrypted in transit (HTTPS).
Access to production data is limited to the app's operator.</p>

<h2>Data retention and deletion</h2>
<p>We keep your store's data for as long as the app is installed, so scans, history,
and settings remain available to you. If you uninstall SpeedPilot, Shopify notifies us
and we permanently delete your store's data - scans, issues, backups, settings, and any
real-visitor performance data - shortly afterward, in accordance with Shopify's data
protection requirements. SpeedPilot does not knowingly collect personal information
about your customers, so there is generally no customer data to separately request or
delete; if you believe otherwise, contact us using the details below.</p>

<h2>Your rights and choices</h2>
<p>You can review, change, or remove most of what SpeedPilot stores directly from the
app: update your target theme and scan preferences in Settings, remove your storefront
password or Slack webhook at any time, and roll back any applied fix from the
Optimizations page. Uninstalling the app triggers deletion of your store's data as
described above. You can also contact us directly to ask what data we hold about your
store or to request its deletion.</p>

<h2>Children's privacy</h2>
<p>SpeedPilot is a business tool for Shopify merchants and is not directed at children.
We do not knowingly collect information from children.</p>

<h2>Changes to this policy</h2>
<p>If we make material changes to this policy, we'll update the "Last updated" date
above. Continued use of the app after a change means you accept the updated policy.</p>

<h2>Contact us</h2>
<p>Questions about this policy or your data can be sent to
<a href="mailto:{{SUPPORT_EMAIL}}">{{SUPPORT_EMAIL}}</a>.</p>
HTML;
    }
}
