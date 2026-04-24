<?php
declare(strict_types=1);

if ( ! class_exists( 'Metis_Help_Documents_Seed' ) ) {
    final class Metis_Help_Documents_Seed {
        public static function run( \Metis\Core\HelpSearchStore $store, bool $force = false ): array {
            $categories = 0;
            foreach ( self::categories() as $category ) {
                $store->upsertCategory(
                    (string) $category['name'],
                    (string) $category['slug'],
                    (int) $category['sort_order']
                );
                $categories++;
            }

            $created = 0;
            $updated = 0;
            foreach ( self::articles() as $article ) {
                $result = $store->upsertSeededArticle( $article, $force );
                if ( (string) ( $result['action'] ?? '' ) === 'created' ) {
                    $created++;
                } elseif ( (string) ( $result['action'] ?? '' ) === 'updated' ) {
                    $updated++;
                }
            }

            $store->rebuildSearchIndex();

            return [
                'categories' => $categories,
                'articles' => count( self::articles() ),
                'created' => $created,
                'updated' => $updated,
            ];
        }

        public static function categories(): array {
            return [
                [ 'name' => 'Getting Started', 'slug' => 'getting-started', 'sort_order' => 10 ],
                [ 'name' => 'Account & Login', 'slug' => 'account-login', 'sort_order' => 20 ],
                [ 'name' => 'Dashboard & Navigation', 'slug' => 'dashboard-navigation', 'sort_order' => 30 ],
                [ 'name' => 'People & Permissions', 'slug' => 'people-permissions', 'sort_order' => 40 ],
                [ 'name' => 'Website', 'slug' => 'website', 'sort_order' => 50 ],
                [ 'name' => 'Newsletter', 'slug' => 'newsletter', 'sort_order' => 60 ],
                [ 'name' => 'Donations', 'slug' => 'donations', 'sort_order' => 70 ],
                [ 'name' => 'Finance', 'slug' => 'finance', 'sort_order' => 80 ],
                [ 'name' => 'Drive & Files', 'slug' => 'drive-files', 'sort_order' => 90 ],
                [ 'name' => 'Calendar', 'slug' => 'calendar', 'sort_order' => 100 ],
                [ 'name' => 'Reports', 'slug' => 'reports', 'sort_order' => 110 ],
                [ 'name' => 'Security', 'slug' => 'security', 'sort_order' => 120 ],
                [ 'name' => 'Hermes Assistant', 'slug' => 'hermes-assistant', 'sort_order' => 130 ],
                [ 'name' => 'Settings', 'slug' => 'settings', 'sort_order' => 140 ],
                [ 'name' => 'Accessibility', 'slug' => 'accessibility', 'sort_order' => 150 ],
                [ 'name' => 'Troubleshooting', 'slug' => 'troubleshooting', 'sort_order' => 160 ],
                [ 'name' => 'System Administration', 'slug' => 'system-administration', 'sort_order' => 170 ],
            ];
        }

        public static function articles(): array {
            return array_merge(
                self::gettingStartedArticles(),
                self::accountArticles(),
                self::peopleArticles(),
                self::websiteArticles(),
                self::newsletterArticles(),
                self::donationArticles(),
                self::financeArticles(),
                self::driveArticles(),
                self::calendarArticles(),
                self::reportArticles(),
                self::securityArticles(),
                self::hermesArticles(),
                self::settingsArticles(),
                self::accessibilityArticles(),
                self::troubleshootingArticles(),
                self::systemAdministrationArticles(),
                self::helpIssueArticles()
            );
        }

        private static function gettingStartedArticles(): array {
            $category = 'getting-started';
            return [
                self::article( 'getting_started.welcome_to_metis', 'Welcome to Metis', $category, 'portal', 'Welcome to the shared Metis workspace and the core ideas behind how modules, records, permissions, and guided help fit together.', 'Use this article when you are new to Metis, returning after a long break, or helping another user get oriented quickly.', [ 'Sign in and confirm that your name, avatar, and active workspace are correct.', 'Start on the Dashboard and review the modules that are visible to your role.', 'Use Help Search whenever you do not know where a task lives or what a button means.' ], [ 'welcome', 'getting started', 'new user', 'metis basics', 'portal overview' ], [ 'new to metis', 'first time using metis', 'how metis works' ] ),
                self::article( 'getting_started.understanding_the_dashboard', 'Understanding the Dashboard', $category, 'portal', 'The Dashboard is the starting page that summarizes work across the platform and gives you a quick way to move into the right module.', 'Use the Dashboard when you need a current snapshot, want to jump into common work, or need to confirm that your access is working as expected.', [ 'Open the Dashboard and scan the cards, counts, and action areas that appear for your role.', 'Select the area that matches your task instead of browsing module-by-module.', 'Return to the Dashboard whenever you want a fresh overview of current activity.' ], [ 'dashboard', 'portal home', 'overview', 'home page', 'navigation' ], [ 'dashboard page', 'portal dashboard', 'main page' ] ),
                self::article( 'getting_started.using_the_left_navigation_menu', 'Using the Left Navigation Menu', $category, 'portal', 'The left navigation menu organizes Metis modules and views so you can move around the system without memorizing direct links.', 'Use it when you know the area you need, when you want to compare two modules, or when you are learning how the platform is structured.', [ 'Open the menu and look for the module name rather than hunting for a specific screen title.', 'Use submenu items to move to the exact view you need inside that module.', 'If a module or view is missing, check your permissions before assuming the page is broken.' ], [ 'left menu', 'navigation menu', 'sidebar', 'module menu', 'where is the page' ], [ 'left navigation', 'sidebar menu', 'how do i find a module' ] ),
                self::article( 'getting_started.searching_across_metis', 'Searching Across Metis', $category, 'portal', 'Metis search is meant to help you find records, objects, and Help content from common words and likely user phrases.', 'Use search when you know what you need but not where it lives, when a page has moved, or when you are matching a person, article, or object code.', [ 'Type a short phrase, object code, or module name into the search area.', 'Start broad, then narrow the wording if the first pass returns too much or too little.', 'Open the matching result and refine your search terms if the result list is not specific enough.' ], [ 'search', 'find', 'global search', 'lookup', 'search bar' ], [ 'search across metis', 'find a page', 'look up a record' ] ),
                self::article( 'getting_started.understanding_toast_messages', 'Understanding Toast Messages', $category, 'portal', 'Toast messages are the small notifications that appear after Metis completes an action or blocks a change.', 'Use this article when you want to understand whether a save succeeded, what a warning means, or whether you need to take another step.', [ 'Read the full toast before closing it, especially after save, publish, import, or approval actions.', 'Treat success toasts as confirmation that the server accepted the action.', 'If the toast reports a warning or error, review the message and correct the form or permission issue before trying again.' ], [ 'toast', 'notification', 'saved message', 'error message', 'success message' ], [ 'what does this toast mean', 'save message', 'popup notification' ] ),
                self::article( 'getting_started.understanding_modals_and_confirmation_windows', 'Understanding Modals and Confirmation Windows', $category, 'portal', 'Metis uses modal windows and confirmation steps to keep edits focused and to reduce accidental changes to important records.', 'Use this article when a dialog opens over the page, when you are asked to confirm a deletion, or when an action pauses for review.', [ 'Read the modal title and summary first so you know whether you are editing, confirming, or previewing.', 'Confirm only after checking the record name, action, and consequences shown in the window.', 'Cancel the modal if anything looks wrong, then reopen it from the correct record or button.' ], [ 'modal', 'confirmation window', 'dialog', 'pop up', 'confirm delete' ], [ 'what is this window', 'confirmation box', 'modal dialog' ] ),
            ];
        }

        private static function accountArticles(): array {
            $category = 'account-login';
            return [
                self::article( 'account_login.signing_in', 'Signing In', $category, 'account', 'Signing in gives you access to the Metis workspace areas that match your account, role, and current security settings.', 'Use this article when you are logging in for the first time, helping a user who cannot access the portal, or checking the expected sign-in flow.', [ 'Open the Metis sign-in page and enter your approved username or email address.', 'Complete any required password, MFA, or passkey step that appears after your primary sign-in step.', 'Wait for the Dashboard or your landing page to load before starting another sign-in attempt.' ], [ 'sign in', 'login', 'access account', 'enter metis', 'authentication' ], [ 'how do i sign in', 'login page', 'cannot log in' ] ),
                self::article( 'account_login.using_passkeys', 'Using Passkeys', $category, 'account', 'Passkeys let you sign in with a device-backed credential instead of typing a password every time.', 'Use passkeys when your organization has enabled them, when you want a faster sign-in flow, or when you are reducing password-only logins.', [ 'Start the sign-in flow and choose the passkey option when it is offered.', 'Approve the request with your device method, such as biometrics or your local device unlock.', 'Return to profile security settings if you need to register, replace, or remove a passkey later.' ], [ 'passkey', 'passwordless', 'biometric login', 'security key', 'webauthn' ], [ 'use passkey', 'sign in with face id', 'passwordless login' ] ),
                self::article( 'account_login.using_mfa', 'Using MFA', $category, 'account', 'Multi-factor authentication adds a second check after sign-in so account access does not depend on a password alone.', 'Use this article when MFA is enabled on your account, when you are setting expectations for staff, or when a code prompt appears unexpectedly.', [ 'Sign in with your normal first factor, then wait for the MFA prompt.', 'Enter the current code from your authenticator method before it expires.', 'If the code fails, sync your device time and try a fresh code instead of repeating the old one.' ], [ 'mfa', '2fa', 'authenticator code', 'verification code', 'second factor' ], [ 'using mfa', 'two factor code', 'why am i being asked for a code' ] ),
                self::article( 'account_login.resetting_your_password', 'Resetting Your Password', $category, 'account', 'Password reset tools help you recover access without creating duplicate accounts or relying on an administrator for routine sign-in issues.', 'Use this article when you forgot your password, when the current password is no longer accepted, or when a reset link is required.', [ 'Use the password reset option from the login page rather than creating a new account.', 'Open the reset link from the most recent email and choose a strong replacement password.', 'Sign in with the new password and complete MFA or passkey confirmation if prompted.' ], [ 'reset password', 'forgot password', 'password recovery', 'new password', 'reset link' ], [ 'forgot my password', 'reset my password', 'password email' ] ),
                self::article( 'account_login.profile_overview', 'Profile Overview', $category, 'account', 'Your profile is the personal account area where you review your details, update visible information, and manage profile-related sign-in preferences.', 'Use this article when you want to understand what the Profile area is for, where to change your own details, or which profile tasks belong to you rather than an administrator.', [ 'Open your profile from the account menu or user area after signing in.', 'Review the sections available on your profile page, such as personal details, avatar, and security methods.', 'Choose the task you need, save your change, and confirm the success message before leaving the page.' ], [ 'profile', 'my profile', 'account profile', 'user profile', 'profile page' ], [ 'what is profile', 'where is my profile', 'what can i do in profile' ] ),
                self::article( 'account_login.updating_your_profile', 'Updating Your Profile', $category, 'account', 'Your profile stores the personal details Metis uses for display, identification, and some user-facing notifications.', 'Use it when your name, phone, preferences, or other visible information needs to stay current for coworkers and system workflows.', [ 'Open your profile from the account area after signing in.', 'Review editable fields and update only the details that have changed.', 'Save the profile and confirm the success message before leaving the page.' ], [ 'profile', 'my account', 'update name', 'user details', 'account settings' ], [ 'update my profile', 'change my details', 'edit my account' ] ),
                self::article( 'account_login.managing_your_avatar_or_initials', 'Managing Your Avatar or Initials', $category, 'account', 'Metis shows either an avatar image or generated initials so people can identify who is signed in and who owns recent actions.', 'Use this article when you want to upload a profile image, remove an outdated image, or understand why initials are shown instead.', [ 'Open your profile settings and find the avatar or image section.', 'Upload a replacement image or remove the current one if initials are preferred.', 'Save the change and refresh the page if the old image remains cached for a short time.' ], [ 'avatar', 'profile image', 'initials', 'user picture', 'photo' ], [ 'change profile photo', 'why do i see initials', 'avatar not updating' ] ),
            ];
        }

        private static function peopleArticles(): array {
            $category = 'people-permissions';
            return [
                self::article( 'people_permissions.understanding_people_records', 'Understanding People Records', $category, 'people', 'People records are the core identity records used across Metis for staff, board members, donors, and other system-linked users.', 'Use this article when you need to understand the difference between a person record, a login account, and role-based access.', [ 'Open the People area and review how records are listed and labeled.', 'Check whether the person already exists before creating a new record.', 'Treat a person record as the source identity record even if login access is added later.' ], [ 'people record', 'person profile', 'user record', 'identity', 'staff record' ], [ 'what is a people record', 'difference between user and person', 'person profile' ] ),
                self::article( 'people_permissions.creating_a_user', 'Creating a User', $category, 'people', 'Creating a user in Metis means creating or completing a person record and then granting the right kind of login access.', 'Use this article when onboarding a new staff member, setting up access for a board user, or preparing a limited admin account.', [ 'Search for the person first so you do not create a duplicate identity record.', 'Complete the required identity and contact details before assigning access.', 'Add the correct roles and verify that the user can reach only the modules they need.' ], [ 'create user', 'new user', 'add person', 'onboarding', 'grant access' ], [ 'how to create a user', 'add new account', 'new staff login' ] ),
                self::article( 'people_permissions.editing_a_user', 'Editing a User', $category, 'people', 'Editing a user updates the stored identity, contact, or access information for an existing Metis account.', 'Use it when a role changes, when a name or email changes, or when you need to correct a user record without replacing it.', [ 'Open the person record from the People list rather than editing from a copied browser tab.', 'Update the identity, role, or access details that changed.', 'Save the record and confirm that the new permissions and profile details display correctly.' ], [ 'edit user', 'update user', 'change role', 'user details', 'person edit' ], [ 'edit a user', 'change a user role', 'update account details' ] ),
                self::article( 'people_permissions.deactivating_a_user', 'Deactivating a User', $category, 'people', 'Deactivation removes active access without deleting the record history tied to the person.', 'Use it when a user should no longer sign in, when temporary access needs to stop, or when you are suspending an account safely.', [ 'Open the correct person record and confirm you are working on the right account.', 'Use the deactivate action instead of deleting the record whenever history must be preserved.', 'Review assigned roles and linked access before closing the record.' ], [ 'deactivate user', 'disable access', 'turn off login', 'suspend account', 'remove sign in' ], [ 'deactivate a user', 'disable user login', 'suspend account' ] ),
                self::article( 'people_permissions.assigning_roles', 'Assigning Roles', $category, 'people', 'Roles control what a user can see and do, so role assignment should match actual job responsibility rather than convenience.', 'Use this article when a user needs new access, when responsibilities change, or when you are checking why a page is visible.', [ 'Review the user’s current responsibilities before adding or removing a role.', 'Assign the smallest role set that covers the needed work.', 'Test or confirm the resulting access after saving so the user sees the expected modules and buttons.' ], [ 'assign role', 'roles', 'permissions', 'access level', 'grant role' ], [ 'assign roles', 'change permissions', 'grant access role' ] ),
                self::article( 'people_permissions.understanding_permissions', 'Understanding Permissions', $category, 'people', 'Permissions are the rule checks behind visibility, editing, creation, deletion, and other sensitive actions in Metis.', 'Use this article when you need to explain why access is allowed or blocked, or when you are reviewing role design with an administrator.', [ 'Start with the user’s assigned roles and the module they are trying to use.', 'Check whether the action is view-only, edit, create, delete, publish, or another module-specific permission.', 'Treat hidden controls and permission denied messages as signs to review access mapping rather than signs of a broken page.' ], [ 'permissions', 'access denied', 'role access', 'allowed actions', 'security access' ], [ 'understanding permissions', 'what does permission denied mean', 'why is access blocked' ] ),
                self::article( 'people_permissions.why_some_pages_or_buttons_are_hidden', 'Why Some Pages or Buttons Are Hidden', $category, 'people', 'Hidden pages, buttons, or actions usually mean the current role does not include the permission required for that feature.', 'Use this article when someone says a page is missing, a button is not visible, or an action appears for one user but not another.', [ 'Confirm which user and role combination is being discussed before troubleshooting the page itself.', 'Compare the missing page or button with the permission needed for that module action.', 'Update the role only if the access is appropriate for the user’s actual responsibility.' ], [ 'permissions', 'hidden button', 'missing page', 'access denied', 'not visible', 'module hidden' ], [ 'i cannot see the button', 'the page is missing', 'role does not allow this action' ] ),
                self::article( 'people_permissions.offboarding_a_user', 'Offboarding a User', $category, 'people', 'Offboarding should remove access cleanly while preserving the person record, activity history, and any audit trail tied to prior work.', 'Use this article when a staff member leaves, when temporary access ends, or when an account should no longer be available.', [ 'Deactivate the user account so sign-in stops immediately.', 'Review roles, shared access, and security methods such as MFA or passkeys.', 'Keep the identity record intact so prior actions, reports, and linked records remain understandable.' ], [ 'offboarding', 'remove user', 'deactivate account', 'staff exit', 'access removal' ], [ 'offboard a user', 'employee left organization', 'remove access after departure' ] ),
            ];
        }

        private static function websiteArticles(): array {
            $category = 'website';
            return [
                self::article( 'website.website_overview', 'Website Overview', $category, 'website', 'The Website area manages pages, menus, templates, redirects, and other public-facing content that appears on the external site.', 'Use this article when you are learning the website module, planning a content update, or helping a non-technical editor work inside the approved workflow.', [ 'Open the Website module and review the available views such as pages, menus, templates, redirects, and theme settings.', 'Choose the correct website area before editing so you do not update the wrong content type.', 'Use preview and publish steps deliberately instead of assuming every save is public.' ], [ 'website', 'pages', 'menus', 'templates', 'public site' ], [ 'website module overview', 'how website works', 'manage public pages' ] ),
                self::article( 'website.creating_a_page', 'Creating a Page', $category, 'website', 'Creating a page adds a new structured page record that can later be previewed, added to navigation, and published to the website.', 'Use this article when you need a new public page for ministry information, events, campaigns, policies, or other site content.', [ 'Start from the Pages area or the approved page creation workflow.', 'Enter the page title, slug, and content structure before thinking about navigation placement.', 'Preview the draft, confirm the layout, and publish only when the content is ready.' ], [ 'create page', 'new page', 'website page', 'add page', 'page draft' ], [ 'create a page', 'new website page', 'add a public page' ] ),
                self::article( 'website.editing_a_page', 'Editing a Page', $category, 'website', 'Editing a page updates the existing content, layout, and publish-ready details of a saved website page.', 'Use it when a page needs corrected copy, refreshed sections, new links, or structural layout changes.', [ 'Open the existing page from the Pages list so you are editing the current record.', 'Update content carefully and review sections, links, and page structure before saving.', 'Preview the result and publish or unpublish based on the intended audience timing.' ], [ 'edit page', 'update page', 'page editor', 'website edit', 'change content' ], [ 'edit website page', 'change page content', 'update public page' ] ),
                self::article( 'website.setting_the_homepage', 'Setting the Homepage', $category, 'website', 'The homepage setting controls which published page Metis serves as the main public landing page.', 'Use this article when the front page needs to change for a redesign, campaign, or seasonal update.', [ 'Confirm that the target page is complete and published before making it the homepage.', 'Use the homepage action from the Website tools instead of renaming or duplicating pages.', 'Open the public site after the change and verify that the homepage route now serves the correct content.' ], [ 'homepage', 'front page', 'set homepage', 'website home', 'landing page' ], [ 'set the homepage', 'change front page', 'website home page' ] ),
                self::article( 'website.managing_menus', 'Managing Menus', $category, 'website', 'Menus control how visitors move through the public site, so menu changes should be accurate, intentional, and easy to scan.', 'Use this article when adding a new page to navigation, cleaning up old links, or reordering the main public menu.', [ 'Open the menu list and choose the correct menu before making edits.', 'Add, remove, or reorder items so labels stay short and clear for public visitors.', 'Preview the navigation path and confirm that every linked page resolves correctly.' ], [ 'menu', 'navigation', 'website menu', 'header links', 'footer links' ], [ 'manage menus', 'website navigation links', 'add page to menu' ] ),
                self::article( 'website.using_templates', 'Using Templates', $category, 'website', 'Templates provide reusable layouts so multiple pages can follow the same structure without rebuilding every section by hand.', 'Use templates when you want consistent design, repeated section patterns, or faster creation of similar pages.', [ 'Choose the template that matches the page type you are creating.', 'Apply the template before spending time on manual layout changes.', 'Review the result in preview mode so you can confirm the template behaves correctly with your page content.' ], [ 'template', 'layout', 'reuse design', 'page pattern', 'website template' ], [ 'using templates', 'reusable layout', 'apply template to page' ] ),
                self::article( 'website.using_blocks_and_rows', 'Using Blocks and Rows', $category, 'website', 'Blocks and rows are the building pieces used to structure page content into readable, reusable sections.', 'Use this article when you are shaping a page layout, reorganizing content, or trying to keep a long page easy to scan.', [ 'Add rows first to establish broad page sections.', 'Place the right blocks inside each row so text, images, and calls to action stay organized.', 'Preview spacing and reading order before publishing so the page remains easy to follow on different screens.' ], [ 'blocks', 'rows', 'layout builder', 'page sections', 'content blocks' ], [ 'using blocks', 'how rows work', 'page builder sections' ] ),
                self::article( 'website.previewing_a_page', 'Previewing a Page', $category, 'website', 'Preview lets you check a page before it becomes public so layout, wording, and links can be confirmed without publishing early.', 'Use preview every time a page changes significantly or when a publish decision depends on a final review.', [ 'Save the current draft so preview reflects the latest content.', 'Open preview and review headings, links, buttons, spacing, and images.', 'Make fixes in the editor and preview again until the page reads clearly.' ], [ 'preview page', 'draft preview', 'before publishing', 'page review', 'website preview' ], [ 'preview a page', 'check draft page', 'review before publish' ] ),
                self::article( 'website.publishing_a_page', 'Publishing a Page', $category, 'website', 'Publishing makes a page available to the public site, so the last review should confirm accuracy, navigation, and timing.', 'Use this article when a draft is ready for visitors and you want to release it intentionally.', [ 'Confirm the page title, slug, content, and links before publishing.', 'Use publish only after preview and approval steps are complete for your team.', 'Verify the public URL after publish so the live page matches expectations.' ], [ 'publish page', 'go live', 'make public', 'website publish', 'launch page' ], [ 'publish a page', 'make page live', 'put page on website' ] ),
                self::article( 'website.seo_basics', 'SEO Basics', $category, 'website', 'SEO basics in Metis focus on clear titles, readable structure, stable URLs, and content that matches what visitors expect to find.', 'Use this article when creating or revising public content that should be easy to find and easy to understand.', [ 'Write a page title and summary that describe the page in plain language.', 'Keep headings clear and keep page structure easy to scan.', 'Avoid unnecessary slug changes once a page is already public unless you are also managing redirects.' ], [ 'seo', 'search engine', 'slug', 'page title', 'public search' ], [ 'seo basics', 'how to improve search visibility', 'page title and slug' ] ),
                self::article( 'website.redirects_for_moved_or_deleted_pages', 'Redirects for Moved or Deleted Pages', $category, 'website', 'Redirects protect visitors from broken links when a page moves, a slug changes, or old content is retired.', 'Use redirects whenever a public path changes and external links, bookmarks, or search results may still point to the old address.', [ 'Record the old path before changing or removing the page.', 'Create a redirect from the old path to the best current replacement.', 'Test the old URL after saving so visitors land on the expected destination.' ], [ 'redirect', 'moved page', 'deleted page', 'changed slug', 'old url' ], [ 'redirect old page', 'moved page link', 'deleted page still in search results' ] ),
            ];
        }

        private static function newsletterArticles(): array {
            $category = 'newsletter';
            return [
                self::article( 'newsletter.newsletter_overview', 'Newsletter Overview', $category, 'newsletter', 'The Newsletter area manages campaign drafts, audience lists, templates, tests, sends, and high-level performance metrics.', 'Use this article when you are planning a campaign, learning where newsletter tasks live, or supporting a communications workflow.', [ 'Review the dashboard, campaign list, template area, and list management areas before starting a send.', 'Choose whether you are editing the message itself, managing the audience, or reviewing delivery results.', 'Use test, schedule, and metrics steps as part of the normal workflow instead of optional extras.' ], [ 'newsletter', 'email campaign', 'audience list', 'template', 'campaign overview' ], [ 'newsletter module overview', 'how newsletter works', 'email campaign workflow' ] ),
                self::article( 'newsletter.creating_a_newsletter_campaign', 'Creating a Newsletter Campaign', $category, 'newsletter', 'A campaign is the specific send record that connects content, audience, timing, and delivery reporting.', 'Use this article when you are preparing a new communication for a selected audience.', [ 'Create a new campaign and enter the subject, sender details, and planned audience.', 'Build or attach the message content using the approved template or editor workflow.', 'Save the campaign as a draft until tests and scheduling are complete.' ], [ 'create campaign', 'newsletter campaign', 'new email', 'campaign draft', 'email send' ], [ 'create a newsletter campaign', 'new newsletter', 'start an email campaign' ] ),
                self::article( 'newsletter.editing_a_newsletter_template', 'Editing a Newsletter Template', $category, 'newsletter', 'Templates keep newsletters visually consistent and reduce repeated formatting work across multiple campaigns.', 'Use templates when you want a reusable email layout or when an approved design needs a controlled update.', [ 'Open the template instead of editing a sent campaign unless you truly need campaign-only changes.', 'Update reusable layout, spacing, branding, or content sections carefully.', 'Send a test after editing so you can verify how the template renders in a real inbox.' ], [ 'newsletter template', 'email template', 'branding', 'layout', 'reusable email' ], [ 'edit newsletter template', 'change email layout', 'template update' ] ),
                self::article( 'newsletter.managing_newsletter_lists', 'Managing Newsletter Lists', $category, 'newsletter', 'Lists determine who receives a campaign, so list membership and list purpose should stay clear and current.', 'Use this article when creating a new audience, cleaning an existing list, or checking who will receive a send.', [ 'Open the list area and verify that you are working on the correct audience.', 'Review membership rules or manual entries before scheduling a send.', 'Keep list names descriptive so future campaigns are easy to target correctly.' ], [ 'newsletter list', 'audience', 'mailing list', 'subscribers', 'recipient list' ], [ 'manage newsletter list', 'who gets this email', 'mailing list members' ] ),
                self::article( 'newsletter.sending_a_test_email', 'Sending a Test Email', $category, 'newsletter', 'Test sends let you verify content, personalization, layout, and links before a campaign reaches the full audience.', 'Use a test email before every real send, especially after changing a template, links, or subject line.', [ 'Save the current campaign or template draft first.', 'Send the test to a controlled inbox that you can review carefully.', 'Open the email and check layout, links, wording, and unsubscribe or footer details.' ], [ 'test email', 'send test', 'preview inbox', 'email check', 'campaign review' ], [ 'send a test email', 'preview newsletter in inbox', 'check email before sending' ] ),
                self::article( 'newsletter.scheduling_a_newsletter', 'Scheduling a Newsletter', $category, 'newsletter', 'Scheduling stores the intended send time so the campaign can go out at the right time without waiting for a manual click.', 'Use this article when the content is final but the campaign should send later.', [ 'Confirm the campaign content, audience, and test results before scheduling.', 'Choose the planned send date and time carefully.', 'Review the scheduled state after saving so you know the campaign is queued correctly.' ], [ 'schedule newsletter', 'send later', 'email schedule', 'planned send', 'campaign timing' ], [ 'schedule a newsletter', 'send this email later', 'set send time' ] ),
                self::article( 'newsletter.viewing_opens_clicks_and_metrics', 'Viewing Opens, Clicks, and Metrics', $category, 'newsletter', 'Newsletter metrics help you evaluate how a campaign performed after delivery.', 'Use this article when reviewing engagement, comparing campaigns, or confirming that a send reached people.', [ 'Open the campaign reporting or metrics area for the correct send.', 'Review delivery, opens, clicks, and any other available indicators together instead of in isolation.', 'Use trends over time to guide future campaigns rather than reacting to one number alone.' ], [ 'opens', 'clicks', 'newsletter metrics', 'campaign report', 'email analytics' ], [ 'view newsletter metrics', 'opens and clicks', 'campaign performance' ] ),
                self::article( 'newsletter.handling_unsubscribes', 'Handling Unsubscribes', $category, 'newsletter', 'Unsubscribes should be respected promptly so list health, consent, and sender reputation remain in good standing.', 'Use this article when someone opts out, when a suppression question comes up, or when you are reviewing list hygiene.', [ 'Review the unsubscribe status from the subscriber or list management area.', 'Do not add a person back to a list without a valid reason and documented approval.', 'Treat repeated unsubscribe questions as a sign to review your list source and expectations.' ], [ 'unsubscribe', 'opt out', 'suppression', 'remove from list', 'email preferences' ], [ 'handle unsubscribe', 'remove person from newsletter', 'why was someone suppressed' ] ),
            ];
        }

        private static function donationArticles(): array {
            $category = 'donations';
            return [
                self::article( 'donations.donations_overview', 'Donations Overview', $category, 'donations', 'The Donations area tracks donors, transactions, deposits, reports, and related giving activity across Metis.', 'Use this article when learning the donations workflow or tracing how gift activity is recorded from entry through reporting.', [ 'Review donors, transactions, deposits, and reports as related parts of one workflow.', 'Use the transaction and deposit areas together when reconciling activity.', 'Treat donor matching and reporting as follow-on steps, not separate systems.' ], [ 'donations', 'giving', 'donor records', 'transactions', 'deposits' ], [ 'donations overview', 'how giving works', 'donation workflow' ] ),
                self::article( 'donations.understanding_donation_transactions', 'Understanding Donation Transactions', $category, 'donations', 'Donation transactions are the individual gift records that support donor history, reporting, and deposit work.', 'Use this article when reviewing a specific gift, checking where a donation came from, or verifying amounts and dates.', [ 'Open the transaction list and identify the correct donation record.', 'Review donor, amount, source, and status details before making changes.', 'Use linked donor and deposit information to understand where the transaction fits in the broader workflow.' ], [ 'donation transaction', 'gift record', 'payment record', 'contribution', 'donation detail' ], [ 'what is a donation transaction', 'gift record details', 'donation payment record' ] ),
                self::article( 'donations.reviewing_stripe_donations', 'Reviewing Stripe Donations', $category, 'donations', 'Stripe donations should be reviewed so online giving records line up with donor identity, transaction status, and downstream finance work.', 'Use this article when monitoring online donations or checking whether a digital gift was recorded correctly.', [ 'Open the relevant transactions and confirm the provider source and amount.', 'Review whether the donor was matched correctly to an existing record.', 'Follow the transaction through deposit and reporting steps if reconciliation is required.' ], [ 'stripe donation', 'online giving', 'payment provider', 'digital donation', 'stripe transaction' ], [ 'review stripe donations', 'online donation record', 'stripe gift' ] ),
                self::article( 'donations.matching_donors_to_people_records', 'Matching Donors to People Records', $category, 'donations', 'Matching donors to people records keeps reporting, history, and communication context accurate across modules.', 'Use it when a donor appears unmatched, duplicated, or disconnected from a known person record.', [ 'Search existing people and donor records before creating a new match.', 'Link the donation activity to the correct person when identity is clear.', 'Review giving history afterward so past gifts appear under the intended record.' ], [ 'match donor', 'link donor', 'people record', 'duplicate donor', 'giving history' ], [ 'match donor to person', 'why is donor unmatched', 'link donation record' ] ),
                self::article( 'donations.creating_deposit_batches', 'Creating Deposit Batches', $category, 'donations', 'Deposit batches group donations for deposit and reconciliation so funds can be tracked cleanly into finance and reporting.', 'Use this article when preparing transactions for deposit review or organizing giving activity into a deposit workflow.', [ 'Select the transactions that belong in the same deposit batch.', 'Create the batch only after checking dates, totals, and included items.', 'Review the resulting batch details before moving into deposit or finance reconciliation.' ], [ 'deposit batch', 'batch donations', 'group transactions', 'bank deposit', 'reconciliation batch' ], [ 'create deposit batch', 'group donations for deposit', 'batch giving records' ] ),
                self::article( 'donations.exporting_donation_reports', 'Exporting Donation Reports', $category, 'donations', 'Donation exports are used to share giving information, reconcile totals, or analyze donor and campaign activity outside the screen view.', 'Use this article when a filtered on-screen view is not enough and a report needs to be reviewed or shared.', [ 'Choose the correct report or filtered dataset first.', 'Apply date, donor, campaign, or status filters before exporting.', 'Review the exported file quickly so you catch missing filters or wrong date ranges early.' ], [ 'export donation report', 'giving report', 'download report', 'donation export', 'campaign report' ], [ 'export donations', 'download giving report', 'save donation report' ] ),
                self::article( 'donations.handling_donation_errors', 'Handling Donation Errors', $category, 'donations', 'Donation errors should be reviewed methodically so the platform reflects the actual gift history without creating duplicates or hiding failed activity.', 'Use this article when a transaction looks wrong, a provider record fails, or totals do not line up.', [ 'Identify whether the problem is a donor match issue, amount issue, provider issue, or deposit issue.', 'Review the transaction history before editing so you do not overwrite useful context.', 'Correct the root cause and re-check related reports or deposit records afterward.' ], [ 'donation error', 'payment error', 'wrong amount', 'failed donation', 'giving issue' ], [ 'handle donation error', 'why is this gift wrong', 'donation problem' ] ),
            ];
        }

        private static function financeArticles(): array {
            $category = 'finance';
            return [
                self::article( 'finance.finance_overview', 'Finance Overview', $category, 'finance', 'The Finance area brings together income, expense, activity review, and export-oriented reporting workflows.', 'Use this article when you need the overall finance layout or want to understand how recorded activity moves into reports.', [ 'Review the main finance sections before entering or exporting data.', 'Use the right workflow for income versus expenses so records stay consistent.', 'Check review and report tools after entry work rather than treating them as separate systems.' ], [ 'finance', 'income', 'expenses', 'financial activity', 'finance workspace' ], [ 'finance overview', 'how finance works', 'financial module' ] ),
                self::article( 'finance.recording_income', 'Recording Income', $category, 'finance', 'Recording income captures incoming financial activity in the correct place for review and reporting.', 'Use it when the organization receives funds that should appear in finance records.', [ 'Start a new income record from the finance area.', 'Enter the amount, date, source, and any classification details required by your workflow.', 'Save the record and review it in the activity view before moving on.' ], [ 'record income', 'new income', 'financial entry', 'revenue', 'income record' ], [ 'record income', 'add revenue', 'new finance income entry' ] ),
                self::article( 'finance.recording_expenses', 'Recording Expenses', $category, 'finance', 'Recording expenses captures outgoing financial activity in a way that supports later review and reporting.', 'Use it when the organization incurs a cost that should appear in finance records.', [ 'Create the expense record from the correct finance view.', 'Enter the amount, date, vendor or payee details, and any needed categorization.', 'Review the saved record in financial activity so the transaction is visible where expected.' ], [ 'record expense', 'new expense', 'vendor cost', 'financial entry', 'expense record' ], [ 'record expense', 'add cost', 'new finance expense entry' ] ),
                self::article( 'finance.reviewing_financial_activity', 'Reviewing Financial Activity', $category, 'finance', 'Financial activity views help you confirm what has been recorded and whether entries are complete and readable.', 'Use this article when checking recent finance work, tracing an entry, or preparing to export a report.', [ 'Open the activity review area and apply the needed filters.', 'Check dates, amounts, and record labels before assuming the underlying numbers are wrong.', 'Use review results to decide whether a correction or an export is needed.' ], [ 'financial activity', 'review entries', 'finance list', 'transaction history', 'record review' ], [ 'review financial activity', 'find finance entry', 'check recorded amounts' ] ),
                self::article( 'finance.exporting_finance_reports', 'Exporting Finance Reports', $category, 'finance', 'Finance exports turn filtered finance data into a shareable file for analysis, recordkeeping, or downstream processing.', 'Use this article when a screen view is not enough or when finance data needs to be reviewed outside Metis.', [ 'Choose the correct report or filtered financial view.', 'Set the date range and any other filters before exporting.', 'Open the export and confirm the totals and range before sharing it.' ], [ 'finance report', 'export finance', 'download report', 'financial export', 'report file' ], [ 'export finance report', 'download financial report', 'save finance data' ] ),
            ];
        }

        private static function driveArticles(): array {
            $category = 'drive-files';
            return [
                self::article( 'drive_files.drive_overview', 'Drive Overview', $category, 'drive', 'Drive connects Metis with managed file storage so users can locate, review, and share files from the correct workspace context.', 'Use this article when learning where files live or when confirming how synced content appears inside Metis.', [ 'Open the Drive area and review folders, file listings, and available filters.', 'Confirm you are working in the correct folder or shared area before uploading or searching.', 'Use permissions and search together when a file appears to be missing.' ], [ 'drive', 'files', 'document storage', 'folders', 'shared files' ], [ 'drive overview', 'how files work', 'where are documents stored' ] ),
                self::article( 'drive_files.uploading_files', 'Uploading Files', $category, 'drive', 'Uploading files adds new documents into the managed Drive workflow so they can be found and used later.', 'Use this article when a file should be stored in Drive and linked to the right folder or work area.', [ 'Choose the destination folder first.', 'Upload the file and wait for the status to complete before navigating away.', 'Review the uploaded item so the file name, location, and visibility are correct.' ], [ 'upload file', 'add document', 'drive upload', 'new file', 'store file' ], [ 'uploading files', 'add a file to drive', 'save document in metis' ] ),
                self::article( 'drive_files.viewing_synced_google_drive_files', 'Viewing Synced Google Drive Files', $category, 'drive', 'Synced Google Drive files appear in Metis so users can locate the same documents without leaving the workspace flow.', 'Use this article when your team relies on shared Google Drive content and you need to confirm sync visibility.', [ 'Open the relevant Drive folder inside Metis.', 'Compare the listed items with the expected Google Drive content.', 'Allow for normal sync delay before concluding that a file is permanently missing.' ], [ 'synced files', 'google drive', 'drive sync', 'shared drive', 'connected files' ], [ 'view synced google drive files', 'where are synced files', 'google drive in metis' ] ),
                self::article( 'drive_files.understanding_folder_permissions', 'Understanding Folder Permissions', $category, 'drive', 'Folder permissions determine who can see or use files in specific Drive locations.', 'Use this article when one person can access a folder and another cannot, or when planning how shared storage should be organized.', [ 'Identify the folder that is affected before troubleshooting individual files.', 'Review who should have access based on job responsibility.', 'Treat missing folder access as a permission issue first and a sync issue second.' ], [ 'folder permissions', 'drive access', 'shared folder', 'cannot open folder', 'file visibility' ], [ 'understanding folder permissions', 'who can see this folder', 'drive folder access' ] ),
                self::article( 'drive_files.searching_files', 'Searching Files', $category, 'drive', 'File search helps you locate documents by likely names, related terms, or folder context instead of manual browsing.', 'Use file search when you know the document exists but the exact folder or title is unclear.', [ 'Search with a short file name or topic phrase first.', 'Add folder or context clues if the results are too broad.', 'Open the result and confirm the location before re-uploading a file that may already exist.' ], [ 'search files', 'find document', 'drive search', 'locate file', 'file lookup' ], [ 'search for a file', 'find missing document', 'locate synced file' ] ),
                self::article( 'drive_files.recovering_missing_files', 'Recovering Missing Files', $category, 'drive', 'A missing file can be caused by search wording, folder permissions, sync delay, or a file being moved.', 'Use this article when a document should exist but does not appear where expected.', [ 'Search by multiple likely names before assuming the file is gone.', 'Check the folder location and whether the current user can see that folder.', 'Review sync timing and recent moves before uploading a replacement copy.' ], [ 'missing file', 'recover file', 'cannot find document', 'drive issue', 'file moved' ], [ 'recover missing file', 'why is file missing', 'document not showing up' ] ),
            ];
        }

        private static function calendarArticles(): array {
            $category = 'calendar';
            return [
                self::article( 'calendar.calendar_overview', 'Calendar Overview', $category, 'calendar', 'Calendar helps the organization create, edit, and review events in a shared scheduling workflow.', 'Use this article when you are learning how Metis calendars are organized or deciding where to manage event information.', [ 'Review the available calendar views and event areas.', 'Choose the correct calendar before creating or editing an event.', 'Use sharing and public or board distinctions deliberately when planning event visibility.' ], [ 'calendar', 'events', 'schedule', 'shared calendar', 'organization calendar' ], [ 'calendar overview', 'how calendar works', 'event scheduling' ] ),
                self::article( 'calendar.creating_events', 'Creating Events', $category, 'calendar', 'Creating an event adds a scheduled record to the correct calendar so the right audience can see it.', 'Use this article when you are adding a meeting, public event, or board event.', [ 'Start from the correct calendar view.', 'Enter the event title, date, time, and any visibility details.', 'Save the event and verify that it appears on the intended calendar.' ], [ 'create event', 'new event', 'add calendar item', 'schedule meeting', 'calendar entry' ], [ 'create an event', 'new calendar event', 'add meeting' ] ),
                self::article( 'calendar.editing_events', 'Editing Events', $category, 'calendar', 'Editing events keeps titles, times, locations, and visibility aligned with the real schedule.', 'Use it when an event changes and the shared calendar must stay accurate.', [ 'Open the existing event record.', 'Update the changed details only after confirming the correct event is selected.', 'Save and re-check the calendar view so the edited event appears as expected.' ], [ 'edit event', 'change meeting', 'update calendar', 'reschedule', 'event details' ], [ 'edit an event', 'change calendar details', 'update meeting time' ] ),
                self::article( 'calendar.managing_board_calendars', 'Managing Board Calendars', $category, 'calendar', 'Board calendars should stay limited to the right audience and should reflect governance work clearly.', 'Use this article when creating or adjusting board-only events and schedules.', [ 'Confirm that the event belongs on the board calendar rather than the public or general calendar.', 'Review visibility and sharing settings before saving.', 'Check the board calendar after saving so the event appears in the correct place.' ], [ 'board calendar', 'board events', 'governance schedule', 'private calendar', 'board access' ], [ 'manage board calendar', 'board meeting calendar', 'board-only event' ] ),
                self::article( 'calendar.managing_public_calendars', 'Managing Public Calendars', $category, 'calendar', 'Public calendars should be accurate, readable, and appropriate for people outside the internal workspace.', 'Use this article when publishing or updating visitor-facing events.', [ 'Confirm that the event is intended for public visibility.', 'Review wording, date, and timing carefully because public calendars are externally visible.', 'Check the public-facing calendar output after changes are saved.' ], [ 'public calendar', 'public event', 'visitor schedule', 'published events', 'community calendar' ], [ 'manage public calendar', 'public event schedule', 'visitor calendar event' ] ),
                self::article( 'calendar.understanding_calendar_sharing', 'Understanding Calendar Sharing', $category, 'calendar', 'Calendar sharing determines who can see events and which event sets are visible to different audiences.', 'Use this article when event visibility is confusing or when a user says they cannot see a calendar.', [ 'Identify which calendar is being discussed first.', 'Check whether the event or calendar is intended to be shared internally, with a specific group, or publicly.', 'Review permissions before recreating events that may simply be hidden from the current user.' ], [ 'calendar sharing', 'who can see event', 'shared calendar', 'permission', 'calendar access' ], [ 'understanding calendar sharing', 'why can’t i see this calendar', 'calendar visibility' ] ),
            ];
        }

        private static function reportArticles(): array {
            $category = 'reports';
            return [
                self::article( 'reports.reports_overview', 'Reports Overview', $category, 'reports', 'Reports collect filtered information from working areas so users can review trends, totals, and supporting detail efficiently.', 'Use this article when you want to understand where reporting lives or how reports relate to operational modules.', [ 'Start by identifying which module or workflow the report belongs to.', 'Choose the report that matches the question you are trying to answer.', 'Use filters before exporting so the report reflects the exact scope you need.' ], [ 'reports', 'analytics', 'export', 'filtered data', 'reporting' ], [ 'reports overview', 'how reports work', 'where are reports' ] ),
                self::article( 'reports.running_a_report', 'Running a Report', $category, 'reports', 'Running a report turns the current report definition and filters into a current result set.', 'Use this article when you need an up-to-date answer from reportable Metis data.', [ 'Choose the correct report first.', 'Set the needed filters and run the report.', 'Review the output on screen before exporting or sharing it.' ], [ 'run report', 'generate report', 'report results', 'load report', 'report output' ], [ 'running a report', 'generate a report', 'open report results' ] ),
                self::article( 'reports.filtering_reports', 'Filtering Reports', $category, 'reports', 'Filters narrow the report so the result set answers the real question instead of returning too much unrelated data.', 'Use filters when date range, status, module, or audience matters.', [ 'Start with the broadest useful filter set such as date or status.', 'Add narrower filters only after confirming the report still contains the expected records.', 'Re-run the report after each major filter change so you know which filter altered the results.' ], [ 'filter reports', 'date range', 'report filter', 'narrow results', 'report criteria' ], [ 'filter a report', 'report date range', 'narrow report results' ] ),
                self::article( 'reports.exporting_reports', 'Exporting Reports', $category, 'reports', 'Exporting a report creates a file version of the current result set for review, delivery, or downstream work.', 'Use export after the screen view already looks correct.', [ 'Confirm filters before exporting.', 'Choose the available export format and download the file.', 'Open the file and spot-check the result instead of assuming the wrong data can be fixed later.' ], [ 'export report', 'download report', 'csv', 'spreadsheet', 'report file' ], [ 'export a report', 'download report file', 'save reporting data' ] ),
                self::article( 'reports.understanding_report_permissions', 'Understanding Report Permissions', $category, 'reports', 'Report permissions determine whether a user can open a report, see its data, or access certain reporting areas.', 'Use this article when one person can run a report and another cannot.', [ 'Identify the report and user involved.', 'Check whether the missing access is caused by module permissions or report-area visibility.', 'Grant broader access only when the user needs the underlying data for their job.' ], [ 'report permissions', 'cannot run report', 'report access', 'missing report', 'permission denied' ], [ 'understanding report permissions', 'why can’t i open this report', 'report access denied' ] ),
            ];
        }

        private static function securityArticles(): array {
            $category = 'security';
            return [
                self::article( 'security.what_the_secure_enclave_does', 'What the Secure Enclave Does', $category, 'security', 'The Secure Enclave is the approval and enforcement layer that protects sensitive actions before Metis changes data or system state.', 'Use this article when you are explaining why some actions pause, require confirmation, or fail with a security-related message.', [ 'Treat the Enclave as the final gate for sensitive work, not as an optional extra.', 'Expect approval, nonce, and permission checks on modifying actions.', 'Use the message detail to understand whether the issue is identity, session, permission, or approval related.' ], [ 'secure enclave', 'security approval', 'protected action', 'approval gate', 'sensitive action' ], [ 'what is secure enclave', 'why is this action protected', 'security approval layer' ] ),
                self::article( 'security.why_actions_need_approval', 'Why Actions Need Approval', $category, 'security', 'Some actions need approval because they affect permissions, settings, content state, or other sensitive records that should not change accidentally.', 'Use this article when a user asks why Metis did not execute a change immediately.', [ 'Read the approval request fully before approving.', 'Confirm the record and action are correct.', 'Approve only when the action is expected and appropriate for your role.' ], [ 'approval', 'needs approval', 'confirm action', 'sensitive change', 'security review' ], [ 'why do i need approval', 'action needs confirmation', 'security approval required' ] ),
                self::article( 'security.understanding_nonces', 'Understanding Nonces', $category, 'security', 'Nonces are short-lived security tokens used to confirm that a request came from a valid, current interaction.', 'Use this article when a save fails after a page sat open too long or when a request is rejected for security reasons.', [ 'Refresh the page if a form or modal has been open for a while.', 'Retry the action from the current screen so the request uses a fresh token.', 'Avoid resubmitting stale browser tabs for sensitive work.' ], [ 'nonce', 'security token', 'expired form', 'csrf', 'security check failed' ], [ 'what is a nonce', 'security token expired', 'why did save fail after waiting' ] ),
                self::article( 'security.permission_denied_messages', 'Permission Denied Messages', $category, 'security', 'Permission denied messages mean Metis blocked an action because the current account is not authorized for it.', 'Use this article when a save, publish, or admin action is rejected even though the page is visible.', [ 'Read the full message and note which action was blocked.', 'Confirm the current role and permission coverage.', 'Request a role review if the access should exist for the job, rather than retrying the same blocked action repeatedly.' ], [ 'permission denied', 'access denied', 'not authorized', 'blocked action', 'security message' ], [ 'permission denied message', 'not authorized', 'why was my action blocked' ] ),
                self::article( 'security.security_logs', 'Security Logs', $category, 'security', 'Security logs help administrators review blocked actions, sensitive operations, and other security-relevant activity.', 'Use this article when auditing issues, tracing approval events, or investigating repeated security failures.', [ 'Open the logging or security review area appropriate for your role.', 'Filter by time, user, or event type to narrow the result set.', 'Use the log context to understand what failed before changing permissions or settings.' ], [ 'security logs', 'audit log', 'blocked actions', 'security event', 'log review' ], [ 'view security logs', 'audit security events', 'check blocked actions' ] ),
                self::article( 'security.safe_system_actions', 'Safe System Actions', $category, 'security', 'Safe system work means using approved workflows, confirming scope, and avoiding repeated high-risk changes without review.', 'Use this article when planning an administrative action that affects many users or core settings.', [ 'Review the action scope before starting.', 'Use the approved workflow instead of bypassing the normal UI or approval path.', 'Verify the result after completion so you catch unexpected impact quickly.' ], [ 'safe action', 'system admin', 'approved workflow', 'secure change', 'admin safety' ], [ 'safe system actions', 'how to make admin changes safely', 'approved admin workflow' ] ),
            ];
        }

        private static function hermesArticles(): array {
            $category = 'hermes-assistant';
            return [
                self::article( 'hermes_assistant.hermes_overview', 'Hermes Overview', $category, 'hermes', 'Hermes is the built-in assistant layer that helps users navigate Metis, explain system behavior, and work within secure boundaries.', 'Use this article when introducing Hermes or setting expectations for what it can and cannot help with.', [ 'Start with a clear task or question.', 'Provide the module or outcome you care about when that context matters.', 'Read Hermes guidance together with permission and approval messages rather than treating it as a bypass.' ], [ 'hermes', 'assistant', 'help assistant', 'guided support', 'system helper' ], [ 'what is hermes', 'hermes overview', 'how does hermes help' ] ),
                self::article( 'hermes_assistant.asking_hermes_for_help', 'Asking Hermes for Help', $category, 'hermes', 'Hermes works best when the request describes the goal, record type, or problem in clear everyday language.', 'Use this article when users want better results from the assistant instead of vague or repeated prompts.', [ 'State the task in plain language.', 'Include the module, record, or problem if you know it.', 'Refine the question if Hermes gives a broad answer instead of the exact help you need.' ], [ 'ask hermes', 'assistant help', 'prompt', 'question', 'guidance' ], [ 'how do i ask hermes', 'best way to ask for help', 'assistant prompt tips' ] ),
                self::article( 'hermes_assistant.hermes_and_secure_enclave_approvals', 'Hermes and Secure Enclave Approvals', $category, 'hermes', 'Hermes can guide or prepare actions, but sensitive work still goes through Secure Enclave approval and permission checks.', 'Use this article when a user thinks Hermes should be able to bypass approval.', [ 'Read Hermes action guidance as preparation, not automatic execution.', 'Expect approval when the requested action changes protected data or system state.', 'Approve only after verifying the target, outcome, and user responsibility.' ], [ 'hermes approval', 'secure enclave', 'assistant approval', 'protected action', 'approval required' ], [ 'why can’t hermes do this directly', 'hermes needs approval', 'assistant and secure enclave' ] ),
                self::article( 'hermes_assistant.hermes_diagnostics', 'Hermes Diagnostics', $category, 'hermes', 'Hermes diagnostics help interpret permissions, route health, and other system-level conditions without changing data directly.', 'Use diagnostics when behavior seems wrong and you need more context before making a change.', [ 'Choose the diagnostic path that matches the actual symptom.', 'Review the returned findings rather than jumping directly to permission or settings changes.', 'Use diagnostic output to guide the next safe action.' ], [ 'diagnostics', 'assistant diagnostics', 'system check', 'troubleshoot', 'health check' ], [ 'hermes diagnostics', 'assistant system check', 'diagnose issue' ] ),
                self::article( 'hermes_assistant.hermes_mission_mode', 'Hermes Mission Mode', $category, 'hermes', 'Mission-oriented Hermes use means asking the assistant to help work through a defined task instead of random disconnected prompts.', 'Use mission mode when the goal is multi-step and you want Hermes to keep context tied to one outcome.', [ 'State the mission clearly at the start.', 'Keep follow-up questions connected to the same goal.', 'Pause and restate the mission if the conversation drifts away from the original task.' ], [ 'mission mode', 'multi step help', 'assistant workflow', 'guided task', 'context' ], [ 'hermes mission mode', 'multi-step assistant help', 'keep context in hermes' ] ),
                self::article( 'hermes_assistant.what_hermes_cannot_do', 'What Hermes Cannot Do', $category, 'hermes', 'Hermes cannot override permissions, skip approvals, or invent access that the current user does not have.', 'Use this article when setting limits for the assistant or explaining a refusal.', [ 'Treat Hermes as a helper inside the platform rules.', 'Do not expect it to bypass Secure Enclave, hidden pages, or missing permissions.', 'Use an administrator workflow if the issue is missing access rather than missing explanation.' ], [ 'cannot do', 'assistant limits', 'permission boundary', 'approval boundary', 'not allowed' ], [ 'what hermes cannot do', 'why did hermes refuse', 'assistant limitations' ] ),
            ];
        }

        private static function settingsArticles(): array {
            $category = 'settings';
            return [
                self::article( 'settings.settings_overview', 'Settings Overview', $category, 'settings', 'Settings centralizes configuration areas that affect how Metis behaves across modules, integrations, and user-facing surfaces.', 'Use this article when locating a configuration area or explaining why some settings are grouped together.', [ 'Identify whether the change affects the organization, a module, a user-facing experience, or a system integration.', 'Open the matching settings section instead of browsing every settings page.', 'Treat settings changes as shared changes that may affect other users.' ], [ 'settings', 'configuration', 'system settings', 'platform settings', 'admin settings' ], [ 'settings overview', 'where do i change settings', 'configuration areas' ] ),
                self::article( 'settings.general_settings', 'General Settings', $category, 'settings', 'General settings control shared identity and baseline workspace details used across the system.', 'Use this article when the organization name, branding basics, or other broad defaults need to change.', [ 'Open the general settings area.', 'Update only the fields relevant to the shared workspace identity.', 'Save and verify the visible result on the affected pages.' ], [ 'general settings', 'organization settings', 'branding', 'workspace identity', 'shared defaults' ], [ 'general settings page', 'change organization details', 'update workspace branding' ] ),
                self::article( 'settings.email_settings', 'Email Settings', $category, 'settings', 'Email settings affect how Metis sends or manages email behavior for campaigns, notifications, and related workflows.', 'Use this article when a sender setting, delivery rule, or shared email default needs review.', [ 'Open the email settings area.', 'Review the current sender and delivery-related fields before changing them.', 'Save and test an affected workflow after major changes.' ], [ 'email settings', 'sender settings', 'delivery settings', 'outbound email', 'mail configuration' ], [ 'email settings page', 'change sender details', 'mail configuration' ] ),
                self::article( 'settings.api_settings', 'API Settings', $category, 'settings', 'API settings store and organize integration details used by external or connected services.', 'Use this article when an integration endpoint, token, or API-level configuration needs review.', [ 'Open the API settings area.', 'Review the exact integration you intend to update.', 'Save changes carefully and verify the connected workflow afterward.' ], [ 'api settings', 'integration', 'endpoint', 'credentials', 'developer settings' ], [ 'api settings page', 'integration settings', 'update api details' ] ),
                self::article( 'settings.theme_settings', 'Theme Settings', $category, 'settings', 'Theme settings affect the shared look and feel of supported Metis surfaces.', 'Use this article when the organization is updating colors or visual defaults for the workspace.', [ 'Open the theme or customization section.', 'Review the current values before changing them.', 'Save and check the resulting appearance on a representative page.' ], [ 'theme settings', 'colors', 'visual settings', 'branding theme', 'customization' ], [ 'theme settings page', 'change colors', 'workspace theme' ] ),
                self::article( 'settings.accessibility_settings', 'Accessibility Settings', $category, 'settings', 'Accessibility settings help users reduce strain and adapt the interface to their reading, contrast, and navigation needs.', 'Use this article when a user needs more readable defaults or when admin staff are reviewing accessibility support.', [ 'Open the accessibility settings area.', 'Change only the options that support the user’s actual need.', 'Confirm the result on a dense page after saving.' ], [ 'accessibility settings', 'low vision', 'readability', 'contrast', 'interface support' ], [ 'accessibility settings page', 'reduce visual strain', 'readability options' ] ),
                self::article( 'settings.user_profile_settings', 'Profile Settings Configuration (Admin)', $category, 'settings', 'Profile settings configuration controls workspace-wide rules that affect how user profiles behave, which fields are required, and what defaults apply across the system.', 'Use this article only when you are configuring profile behavior for the workspace as an administrator, not when you are simply editing your own profile.', [ 'Open the settings area and go to the profile configuration section.', 'Review the rule or default you want to change for all users.', 'Save the change and confirm the effect from a normal user profile if validation is needed.' ], [ 'profile configuration', 'admin profile settings', 'workspace profile rules', 'profile defaults', 'account settings admin' ], [ 'configure profile settings', 'admin profile rules', 'workspace profile configuration' ] ),
                self::article( 'settings.module_settings', 'Module Settings', $category, 'settings', 'Module settings apply to specific parts of Metis and should be changed with a clear understanding of who uses that module.', 'Use this article when a feature area such as calendar, drive, or newsletter needs a targeted configuration update.', [ 'Open the relevant module settings section.', 'Confirm the change belongs to that module and not a broader shared setting.', 'Save and test the affected module behavior after the update.' ], [ 'module settings', 'feature settings', 'calendar settings', 'drive settings', 'newsletter settings' ], [ 'module settings page', 'change settings for one module', 'feature configuration' ] ),
            ];
        }

        private static function accessibilityArticles(): array {
            $category = 'accessibility';
            return [
                self::article( 'accessibility.accessibility_overview', 'Accessibility Overview', $category, 'accessibility', 'Accessibility in Metis is about keeping the interface readable, keyboard-usable, and low-friction for a wide range of users.', 'Use this article when choosing a more comfortable way to work or supporting another user’s access needs.', [ 'Start with the accessibility options already available in the workspace.', 'Adjust the interface based on the actual difficulty you are experiencing.', 'Revisit the settings after using dense pages so you can refine what helps most.' ], [ 'accessibility', 'readability', 'keyboard use', 'contrast', 'comfort' ], [ 'accessibility overview', 'make metis easier to use', 'readability support' ] ),
                self::article( 'accessibility.using_low_vision_mode', 'Using Low-Vision Mode', $category, 'accessibility', 'Low-vision support aims to make key text and controls easier to identify without changing your core work process.', 'Use this article when standard sizing or contrast makes the interface hard to read.', [ 'Open the accessibility controls and enable the low-vision option if available.', 'Review dense pages after enabling it so you can confirm the change helps.', 'Pair the setting with browser zoom if you still need larger content.' ], [ 'low vision', 'larger text', 'higher contrast', 'readability mode', 'visual support' ], [ 'using low vision mode', 'make text easier to read', 'higher visibility mode' ] ),
                self::article( 'accessibility.keyboard_navigation', 'Keyboard Navigation', $category, 'accessibility', 'Keyboard navigation helps users move through Metis without relying on a mouse for core actions.', 'Use this article when testing accessibility, reducing repetitive pointer use, or supporting a keyboard-first workflow.', [ 'Use Tab and Shift+Tab to move between interactive controls.', 'Watch the visible focus state so you know where the current action will land.', 'Use Enter or Space only when the focused control is the one you intend to activate.' ], [ 'keyboard navigation', 'tab order', 'focus', 'no mouse', 'keyboard only' ], [ 'keyboard navigation', 'use metis without mouse', 'tab through controls' ] ),
                self::article( 'accessibility.reducing_visual_fatigue', 'Reducing Visual Fatigue', $category, 'accessibility', 'Reducing visual fatigue means using settings and workflows that make long work sessions more sustainable.', 'Use this article when dense interfaces, contrast, or long reading sessions are causing strain.', [ 'Enable the accessibility options that improve contrast or readability for you.', 'Break long tasks into shorter focused passes when possible.', 'Use search and filters to reduce how much information you need to scan at once.' ], [ 'visual fatigue', 'eye strain', 'reduce strain', 'contrast', 'readability' ], [ 'reducing visual fatigue', 'less eye strain', 'make pages easier to scan' ] ),
                self::article( 'accessibility.reading_dense_pages', 'Reading Dense Pages', $category, 'accessibility', 'Dense pages are easier to manage when you rely on headings, filters, and deliberate scanning instead of reading every control in order.', 'Use this article when a page feels cluttered or cognitively heavy.', [ 'Start with the page title and section headings before reading the details.', 'Use filters, search, or table controls to narrow what you are looking at.', 'Work one section at a time instead of trying to process the whole page at once.' ], [ 'dense page', 'too much information', 'scan page', 'readability', 'page structure' ], [ 'reading dense pages', 'page feels overwhelming', 'too much on screen' ] ),
            ];
        }

        private static function troubleshootingArticles(): array {
            $category = 'troubleshooting';
            return [
                self::article( 'troubleshooting.search_shows_no_results', 'Search Shows No Results', $category, 'troubleshooting', 'No search results can be caused by spelling, access scope, status filters, or the search terms being too narrow.', 'Use this article when Help Search or module search does not find something you expect to exist.', [ 'Check spelling and try fewer words first.', 'Search by module name, title fragment, or object code if available.', 'Confirm that the record or article is published or visible to your role.' ], [ 'no results', 'search empty', 'cannot find', 'search problem', 'missing result' ], [ 'search shows no results', 'nothing found', 'why is search empty' ] ),
                self::article( 'troubleshooting.a_page_will_not_load', 'A Page Will Not Load', $category, 'troubleshooting', 'A page that does not load may be blocked by permissions, session state, a stale link, or a temporary system problem.', 'Use this article when navigation reaches a blank, missing, or blocked page.', [ 'Refresh the page once and confirm the link is still correct.', 'Check whether the page should be visible to your current role.', 'Try entering the page from the parent module instead of from an old bookmark.' ], [ 'page not loading', 'cannot open page', 'blank page', 'missing page', 'load error' ], [ 'page will not load', 'cannot open this screen', 'why is page blank' ] ),
                self::article( 'troubleshooting.a_button_does_nothing', 'A Button Does Nothing', $category, 'troubleshooting', 'A button that appears inactive can be tied to validation, permissions, a stale page, or an action waiting on required fields.', 'Use this article when a visible action does not complete the expected task.', [ 'Check the form for missing required fields or unresolved warnings.', 'Refresh the page if it has been open for a long time.', 'Review whether the action is protected by approval or permission checks.' ], [ 'button does nothing', 'click not working', 'save button', 'inactive action', 'stuck action' ], [ 'button does nothing', 'clicking save does nothing', 'action not responding' ] ),
                self::article( 'troubleshooting.i_do_not_see_a_module', 'I Do Not See a Module', $category, 'troubleshooting', 'Missing modules are most often caused by role visibility rather than a broken installation.', 'Use this article when a user expects to see a module in navigation and it is not there.', [ 'Confirm which role the user currently has.', 'Check whether the module is intended for that role.', 'Request an access review only if the missing module is actually required for the user’s work.' ], [ 'module missing', 'cannot see module', 'sidebar missing', 'permission', 'role access' ], [ 'i do not see a module', 'module missing from menu', 'why is module hidden' ] ),
                self::article( 'troubleshooting.i_cannot_save_changes', 'I Cannot Save Changes', $category, 'troubleshooting', 'Save problems are commonly caused by missing required fields, permission limits, nonce expiry, or invalid data.', 'Use this article when the page loads but a change will not persist.', [ 'Review required fields and validation messages first.', 'Refresh the page if the form has been open for a while.', 'Confirm you have permission for the type of change you are making.' ], [ 'cannot save', 'save failed', 'validation error', 'permission denied', 'security check failed' ], [ 'i cannot save changes', 'save failed', 'why will this not save' ] ),
                self::article( 'troubleshooting.email_did_not_send', 'Email Did Not Send', $category, 'troubleshooting', 'Email delivery issues can come from configuration, list state, message content, or a workflow not reaching the actual send step.', 'Use this article when a newsletter, notification, or test email does not arrive as expected.', [ 'Confirm the message was actually sent or scheduled rather than left as a draft.', 'Check the target address or audience and the relevant send status.', 'Review email settings if the issue affects more than one message.' ], [ 'email not sent', 'message missing', 'newsletter issue', 'delivery problem', 'test email' ], [ 'email did not send', 'why did email not arrive', 'missing newsletter email' ] ),
                self::article( 'troubleshooting.file_sync_looks_delayed', 'File Sync Looks Delayed', $category, 'troubleshooting', 'File sync delay usually means the system has not finished reflecting an external or shared Drive change yet.', 'Use this article when a recently uploaded or moved file does not appear right away.', [ 'Wait briefly and refresh the folder or search result.', 'Search by file name before uploading a duplicate copy.', 'Escalate only if the file still does not appear after a reasonable sync window.' ], [ 'sync delayed', 'file delay', 'google drive sync', 'missing recent file', 'file not updated' ], [ 'file sync delayed', 'why is file not showing yet', 'drive sync looks slow' ] ),
            ];
        }

        private static function systemAdministrationArticles(): array {
            $category = 'system-administration';
            return [
                self::article( 'system_administration.system_updates_overview', 'System Updates Overview', $category, 'system', 'System updates affect shared functionality, so they should be reviewed, scheduled, and verified carefully.', 'Use this article when administrators are planning maintenance or reviewing release-related work.', [ 'Review the scope of the update before applying it.', 'Plan changes during an appropriate maintenance window when needed.', 'Verify health and core workflows after the update completes.' ], [ 'system update', 'maintenance', 'release', 'platform update', 'admin change' ], [ 'system updates overview', 'maintenance update', 'platform release' ] ),
                self::article( 'system_administration.checking_system_health', 'Checking System Health', $category, 'system', 'System health checks help administrators spot issues before they become user-facing problems.', 'Use this article when monitoring the platform or validating the environment after changes.', [ 'Open the health or diagnostics area available to administrators.', 'Review current warnings, failures, or unusual patterns.', 'Use the findings to decide whether logs, jobs, or configuration need more attention.' ], [ 'system health', 'diagnostics', 'health check', 'admin review', 'platform status' ], [ 'check system health', 'is metis healthy', 'platform diagnostics' ] ),
                self::article( 'system_administration.viewing_logs', 'Viewing Logs', $category, 'system', 'Logs help administrators understand what happened before and after system events, failures, or protected actions.', 'Use this article when investigating recurring problems or reviewing recent administrative activity.', [ 'Open the correct log area for the type of issue you are investigating.', 'Filter by time, module, or event type when possible.', 'Read the surrounding context rather than focusing on one isolated line.' ], [ 'logs', 'system logs', 'audit logs', 'error logs', 'activity review' ], [ 'viewing logs', 'check system logs', 'where are the logs' ] ),
                self::article( 'system_administration.running_maintenance', 'Running Maintenance', $category, 'system', 'Maintenance actions should be performed through approved admin workflows so the system stays consistent and auditable.', 'Use this article when a known maintenance step, cleanup, or rebuild process is required.', [ 'Confirm the maintenance action and its expected impact first.', 'Run it from the approved admin surface or workflow.', 'Verify the result and review health indicators when the action completes.' ], [ 'maintenance', 'run maintenance', 'admin action', 'cleanup', 'rebuild' ], [ 'running maintenance', 'perform admin maintenance', 'approved maintenance action' ] ),
                self::article( 'system_administration.understanding_background_jobs', 'Understanding Background Jobs', $category, 'system', 'Background jobs let Metis perform heavier work outside normal page loads so user-facing screens remain responsive.', 'Use this article when a task is queued, delayed, or completed asynchronously.', [ 'Treat queued work as expected behavior for heavier operations.', 'Review job status before retrying the same action repeatedly.', 'Use job and health views together when background work appears stuck.' ], [ 'background jobs', 'queue', 'async', 'scheduled work', 'job status' ], [ 'understanding background jobs', 'why is this queued', 'asynchronous task' ] ),
                self::article( 'system_administration.backup_and_recovery_basics', 'Backup and Recovery Basics', $category, 'system', 'Backup and recovery work should protect the system first and should follow approved administrative procedures.', 'Use this article when discussing what backups are for and how recovery should be approached at a basic level.', [ 'Confirm the scope of the issue before moving toward recovery work.', 'Use backup workflows only from authorized admin areas.', 'Document what was restored and verify the affected workflows afterward.' ], [ 'backup', 'recovery', 'restore', 'system admin', 'data protection' ], [ 'backup and recovery basics', 'restore system basics', 'what are backups for' ] ),
            ];
        }

        private static function article(
            string $seedKey,
            string $title,
            string $categorySlug,
            string $moduleKey,
            string $focus,
            string $when,
            array $steps,
            array $tags,
            array $searchTerms
        ): array {
            $profile = self::profiles()[ $moduleKey ] ?? self::profiles()['portal'];
            $baseTags = array_merge(
                $tags,
                (array) ( $profile['tags'] ?? [] ),
                preg_split( '/[\s&\/-]+/', strtolower( $title ) ) ?: []
            );
            $baseTags = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn ( string $item ): string => trim( strtolower( preg_replace( '/[^a-z0-9 _-]/', '', $item ) ?? '' ) ),
                            $baseTags
                        ),
                        static fn ( string $item ): bool => $item !== ''
                    )
                )
            );

            $searchTerms = array_values(
                array_unique(
                    array_filter(
                        array_merge( [ $title ], $searchTerms, (array) ( $profile['search_terms'] ?? [] ), $baseTags ),
                        static fn ( mixed $value ): bool => is_string( $value ) && trim( $value ) !== ''
                    )
                )
            );

            return [
                'seed_key' => $seedKey,
                'title' => $title,
                'slug' => self::slugify( $title ),
                'summary' => self::summary( $focus ),
                'content' => self::content( $title, $focus, $when, $steps, $profile ),
                'category_slug' => $categorySlug,
                'status' => 'published',
                'tags' => array_slice( $baseTags, 0, 14 ),
                'search_terms' => implode( ', ', $searchTerms ),
            ];
        }

        private static function summary( string $focus ): string {
            $summary = trim( preg_replace( '/\s+/', ' ', $focus ) ?? '' );
            if ( function_exists( 'mb_substr' ) ) {
                return mb_substr( $summary, 0, 220 );
            }

            return substr( $summary, 0, 220 );
        }

        private static function content( string $title, string $focus, string $when, array $steps, array $profile ): string {
            $moduleName = (string) ( $profile['module_name'] ?? 'Metis' );
            $moduleDescription = (string) ( $profile['description'] ?? 'This area supports a specific part of the Metis workflow.' );
            $notes = (array) ( $profile['notes'] ?? [] );
            $issues = (array) ( $profile['issues'] ?? [] );
            $related = (array) ( $profile['related'] ?? [] );
            $notes = array_values( array_unique( array_merge( [
                'What you can see and do depends on your role, the current record, and whether the action needs approval.',
                'Use the normal workflow for this area instead of skipping ahead with old bookmarks or duplicate tabs.',
            ], $notes ) ) );
            $issues = array_values( array_unique( array_merge( [
                'The page or button is hidden because the current role does not include that action.',
                'The record is incomplete, finalized, or still waiting on an earlier workflow step.',
            ], $issues ) ) );

            $html = [];
            $html[] = '<h1>' . self::escape( $title ) . '</h1>';
            $html[] = '<h2>What this is</h2>';
            $html[] = '<p>' . self::escape( $focus ) . '</p>';
            $html[] = '<p>' . self::escape( $moduleDescription ) . '</p>';
            $html[] = '<h2>When to use it</h2>';
            $html[] = '<p>' . self::escape( $when ) . '</p>';
            $html[] = '<h2>How to use it</h2>';
            $html[] = '<p>Use the normal ' . self::escape( $moduleName ) . ' workflow in order so you can confirm the right screen, the right record, and the right outcome before moving on.</p>';
            $html[] = '<ol>';
            foreach ( $steps as $step ) {
                $html[] = '<li>' . self::escape( (string) $step ) . '</li>';
            }
            $html[] = '</ol>';
            $html[] = '<h2>Important notes</h2>';
            $html[] = '<p>Keep these points in mind while working in ' . self::escape( $moduleName ) . '.</p>';
            $html[] = '<ul>';
            foreach ( $notes as $note ) {
                $html[] = '<li>' . self::escape( (string) $note ) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '<h2>Common issues</h2>';
            $html[] = '<p>Most problems in this area can be traced to access, record state, or working out of sequence. Check these points before escalating.</p>';
            $html[] = '<ul>';
            foreach ( $issues as $issue ) {
                $html[] = '<li>' . self::escape( (string) $issue ) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '<h2>Related areas</h2>';
            $html[] = '<p>The following areas commonly connect to the same task or provide the next step in the workflow.</p>';
            $html[] = '<ul>';
            foreach ( $related as $item ) {
                $html[] = '<li>' . self::escape( (string) $item ) . '</li>';
            }
            $html[] = '</ul>';

            return implode( "\n", $html );
        }

        private static function helpIssueArticles(): array {
            $catalog = require dirname( __DIR__, 2 ) . '/config/hermes/help_issue_catalog.php';
            $articles = [];

            foreach ( is_array( $catalog ) ? $catalog : [] as $issue ) {
                if ( ! is_array( $issue ) ) {
                    continue;
                }

                $articles[] = self::issueArticle( $issue );
            }

            return $articles;
        }

        private static function issueArticle( array $issue ): array {
            $title = (string) ( $issue['title'] ?? 'Help Issue' );
            $moduleKey = (string) ( $issue['module_key'] ?? 'portal' );
            $profile = self::profiles()[ $moduleKey ] ?? self::profiles()['portal'];

            $steps = array_values( array_map( 'strval', (array) ( $issue['steps'] ?? [] ) ) );
            $checks = array_values( array_map( 'strval', (array) ( $issue['checks'] ?? [] ) ) );
            $adminEscalation = array_values( array_map( 'strval', (array) ( $issue['admin_escalation'] ?? [] ) ) );
            $relatedArticles = array_values( array_map( 'strval', (array) ( $issue['related_articles'] ?? [] ) ) );
            $searchTerms = array_values( array_map( 'strval', (array) ( $issue['search_terms'] ?? [] ) ) );
            $phrases = array_values( array_map( 'strval', (array) ( $issue['phrases'] ?? [] ) ) );
            $permissions = array_values( array_map( 'strval', (array) ( $issue['permissions'] ?? [] ) ) );
            $featureFlags = array_values( array_map( 'strval', (array) ( $issue['feature_flags'] ?? [] ) ) );
            $classification = strtoupper( (string) ( $issue['classification'] ?? 'WORKFLOW' ) );

            $baseTags = array_merge(
                [ 'help issue', 'issue resolution', strtolower( (string) ( $issue['module'] ?? 'metis' ) ), strtolower( $classification ) ],
                $searchTerms,
                $phrases,
                (array) ( $profile['tags'] ?? [] )
            );
            $tags = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            static fn ( string $item ): string => trim( strtolower( preg_replace( '/[^a-z0-9 _-]/', '', $item ) ?? '' ) ),
                            $baseTags
                        ),
                        static fn ( string $item ): bool => $item !== ''
                    )
                )
            );

            $content = self::issueContent( $issue, $profile );

            return [
                'seed_key' => 'help_issue.' . (string) ( $issue['key'] ?? self::slugify( $title ) ),
                'title' => $title,
                'slug' => self::slugify( $title . '-' . (string) ( $issue['key'] ?? 'issue' ) ),
                'summary' => self::summary( (string) ( $issue['summary'] ?? '' ) ),
                'content' => $content,
                'category_slug' => self::issueCategorySlug( $moduleKey ),
                'status' => 'published',
                'tags' => array_slice( $tags, 0, 18 ),
                'search_terms' => implode( ', ', array_values( array_unique( array_merge( [ $title ], $searchTerms, $phrases, $permissions, $featureFlags ) ) ) ),
                'module_key' => $moduleKey,
                'action_key' => (string) ( $issue['action'] ?? '' ),
            ];
        }

        private static function issueCategorySlug( string $moduleKey ): string {
            return match ( $moduleKey ) {
                'finance' => 'finance',
                'donations' => 'donations',
                'newsletter' => 'newsletter',
                'website' => 'website',
                'drive' => 'drive-files',
                'calendar' => 'calendar',
                'people' => 'people-permissions',
                'reports' => 'reports',
                'settings' => 'settings',
                'help' => 'troubleshooting',
                default => 'troubleshooting',
            };
        }

        private static function issueContent( array $issue, array $profile ): string {
            $title = (string) ( $issue['title'] ?? 'Help Issue' );
            $summary = (string) ( $issue['summary'] ?? '' );
            $steps = array_values( array_map( 'strval', (array) ( $issue['steps'] ?? [] ) ) );
            $checks = array_values( array_map( 'strval', (array) ( $issue['checks'] ?? [] ) ) );
            $adminEscalation = array_values( array_map( 'strval', (array) ( $issue['admin_escalation'] ?? [] ) ) );
            $relatedArticles = array_values( array_map( 'strval', (array) ( $issue['related_articles'] ?? [] ) ) );
            $permissions = array_values( array_map( 'strval', (array) ( $issue['permissions'] ?? [] ) ) );
            $featureFlags = array_values( array_map( 'strval', (array) ( $issue['feature_flags'] ?? [] ) ) );

            $html = [];
            $html[] = '<h1>' . self::escape( $title ) . '</h1>';
            $html[] = '<h2>What this is</h2>';
            $html[] = '<p>' . self::escape( $summary ) . '</p>';
            $html[] = '<p>' . self::escape( (string) ( $profile['description'] ?? 'This workflow depends on a documented Metis path.' ) ) . '</p>';
            $html[] = '<p>This article is meant to help a user or administrator confirm whether the problem belongs to normal workflow behavior, record state, access rules, or a genuine system fault before a bigger intervention is attempted.</p>';
            $html[] = '<h2>When to use it</h2>';
            $html[] = '<p>Use this article when the same task works for one user but not another, when a visible control does not behave as expected, or when a common workflow phrase matches this issue.</p>';
            $html[] = '<p>It is especially useful when the user report is short or informal, such as "it will not save," "I do not see the button," or "nothing happens when I click it," because those symptoms often map to the same small set of checks.</p>';
            $html[] = '<h2>How to use it</h2>';
            $html[] = '<p>Start with the normal workflow first. That usually reveals whether the issue is caused by navigation, record state, validation, permissions, or configuration.</p>';
            $html[] = '<p>Work from the visible screen outward instead of assuming a hidden backend problem. In most cases, the fastest resolution comes from confirming the current module, the current record, and the exact step the user was trying to complete.</p>';
            $html[] = '<ol>';
            foreach ( $steps as $step ) {
                $html[] = '<li>' . self::escape( $step ) . '</li>';
            }
            $html[] = '</ol>';
            $html[] = '<h2>Important notes</h2>';
            $html[] = '<p>Keep these points in mind before deciding the workflow is broken. Metis intentionally uses permissions, approval checks, finalized states, and module configuration to block unsafe actions that would otherwise look like ordinary clicks.</p>';
            $html[] = '<ul>';
            foreach ( $checks as $check ) {
                $html[] = '<li>' . self::escape( $check ) . '</li>';
            }
            foreach ( $permissions as $permission ) {
                $html[] = '<li>' . self::escape( 'Permission dependency: ' . $permission ) . '</li>';
            }
            foreach ( $featureFlags as $flag ) {
                $html[] = '<li>' . self::escape( 'Configuration dependency: ' . $flag ) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '<h2>Common issues</h2>';
            $html[] = '<p>Most failures in this path come from a small set of repeatable causes. The user may be on the wrong screen, a required field may be missing, a prior step may not be finished, or the role may not include the needed action.</p>';
            $html[] = '<ul>';
            foreach ( $checks as $check ) {
                $html[] = '<li>' . self::escape( $check ) . '</li>';
            }
            foreach ( $adminEscalation as $item ) {
                $html[] = '<li>' . self::escape( 'Escalate when: ' . $item ) . '</li>';
            }
            $html[] = '</ul>';
            $html[] = '<h2>Related areas</h2>';
            $html[] = '<p>Use these related areas to confirm the next safe step before making any privileged or system-wide change. If the issue spans more than one module, these linked areas usually explain the missing piece of the workflow.</p>';
            $html[] = '<ul>';
            foreach ( $relatedArticles as $article ) {
                $html[] = '<li>' . self::escape( $article ) . '</li>';
            }
            $html[] = '</ul>';

            return implode( "\n", $html );
        }

        private static function escape( string $value ): string {
            return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        }

        private static function slugify( string $value ): string {
            $value = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $value ) ?? '' ) );
            return trim( $value, '-' );
        }

        private static function profiles(): array {
            return [
                'portal' => [
                    'module_name' => 'Dashboard and Navigation',
                    'description' => 'The portal shell, dashboard, navigation, and shared search tools help users move through Metis without guessing where a feature lives.',
                    'tags' => [ 'dashboard', 'portal', 'navigation', 'help', 'search' ],
                    'search_terms' => [ 'dashboard help', 'portal help', 'navigation help' ],
                    'notes' => [
                        'What appears on the dashboard and in navigation depends on your current role.',
                        'A hidden item is usually a permission or visibility issue, not a deleted feature.',
                        'Use Help Search when you know the task but not the exact module.'
                    ],
                    'issues' => [
                        'If a page looks missing, enter it from the module menu instead of an old bookmark.',
                        'If search returns nothing, try fewer words or search by module name.'
                    ],
                    'related' => [ 'Dashboard', 'Search', 'Notifications', 'User Profile', 'People & Permissions' ],
                ],
                'account' => [
                    'module_name' => 'Account and Login',
                    'description' => 'Account access depends on the current authentication method, the active session, and the security requirements attached to the user account.',
                    'tags' => [ 'account', 'login', 'mfa', 'passkey', 'profile' ],
                    'search_terms' => [ 'sign in help', 'account help', 'login support' ],
                    'notes' => [
                        'Use your real account instead of creating a second account when access stops working.',
                        'MFA and passkey prompts are normal security steps, not errors by themselves.',
                        'Profile changes may affect how your name or avatar appears across Metis.'
                    ],
                    'issues' => [
                        'If sign-in fails repeatedly, confirm that you are using the correct username or email first.',
                        'If a code or passkey prompt fails, refresh the flow and retry with a current device prompt or current code.'
                    ],
                    'related' => [ 'User Profile', 'Login', 'MFA', 'Passkeys', 'Troubleshooting' ],
                ],
                'people' => [
                    'module_name' => 'People and Permissions',
                    'description' => 'People records and access controls are closely linked, so identity and permissions should be maintained together and reviewed carefully.',
                    'tags' => [ 'people', 'roles', 'permissions', 'users', 'access' ],
                    'search_terms' => [ 'people module help', 'role help', 'permission help' ],
                    'notes' => [
                        'A person record can exist without broad login access.',
                        'Roles should match actual responsibility, not temporary convenience.',
                        'Deactivation usually preserves useful history better than deletion.'
                    ],
                    'issues' => [
                        'If a user cannot see a page, compare their role to the permission needed for that module.',
                        'If duplicate people appear, stop and review existing records before creating another one.'
                    ],
                    'related' => [ 'People', 'Roles & Permissions', 'User Profile', 'Security', 'Troubleshooting' ],
                ],
                'website' => [
                    'module_name' => 'Website',
                    'description' => 'Website work combines content editing, structure, navigation, preview, publishing, and public routing, so changes should follow a deliberate content workflow.',
                    'tags' => [ 'website', 'pages', 'publish', 'template', 'navigation' ],
                    'search_terms' => [ 'website help', 'page editor help', 'public site help' ],
                    'notes' => [
                        'Preview is the normal checkpoint before publishing.',
                        'Slug and redirect changes affect public visitors and saved links.',
                        'Use the correct content type so pages, posts, templates, and menus stay separate.'
                    ],
                    'issues' => [
                        'If the public page is wrong, confirm whether you edited the page, the menu, or the homepage setting.',
                        'If a moved page breaks links, create or review redirects before publishing more changes.'
                    ],
                    'related' => [ 'Website', 'Dashboard', 'Search', 'Reports', 'Settings' ],
                ],
                'newsletter' => [
                    'module_name' => 'Newsletter',
                    'description' => 'Newsletter work ties together message content, audience, test sends, scheduling, and delivery results.',
                    'tags' => [ 'newsletter', 'campaign', 'email', 'template', 'audience' ],
                    'search_terms' => [ 'newsletter help', 'campaign help', 'email send help' ],
                    'notes' => [
                        'Always test before a real send.',
                        'List selection matters as much as message content.',
                        'Unsubscribes should be handled promptly and respectfully.'
                    ],
                    'issues' => [
                        'If an email did not send, confirm that the campaign was scheduled or sent instead of left as a draft.',
                        'If the audience looks wrong, review list membership and filters before sending again.'
                    ],
                    'related' => [ 'Newsletter', 'Settings', 'Reports', 'Notifications', 'People' ],
                ],
                'donations' => [
                    'module_name' => 'Donations',
                    'description' => 'Donation workflows depend on accurate donor identity, transaction detail, deposit grouping, and export-ready reporting.',
                    'tags' => [ 'donations', 'donor', 'transaction', 'deposit', 'giving' ],
                    'search_terms' => [ 'donation help', 'donor help', 'giving records' ],
                    'notes' => [
                        'Match donor identity carefully before creating new records.',
                        'Deposit and report work is easier when transaction detail is complete first.',
                        'Preserve history when resolving donation issues so the audit trail remains understandable.'
                    ],
                    'issues' => [
                        'If giving totals look wrong, compare transactions, deposits, and exports before editing a donor record.',
                        'If an online gift looks unmatched, review existing people and donor records before creating another donor.'
                    ],
                    'related' => [ 'Donations', 'People', 'Finance', 'Reports', 'Dashboard' ],
                ],
                'finance' => [
                    'module_name' => 'Finance',
                    'description' => 'Finance records should be entered consistently so activity review and exported reporting stay reliable and scalable.',
                    'tags' => [ 'finance', 'income', 'expense', 'activity', 'report' ],
                    'search_terms' => [ 'finance help', 'financial record help', 'finance reports' ],
                    'notes' => [
                        'Use the correct entry type for income versus expense work.',
                        'Review recorded activity before exporting a report.',
                        'Shared finance settings can affect multiple users and workflows.'
                    ],
                    'issues' => [
                        'If a record seems missing, check the current filters before re-entering the transaction.',
                        'If a report export looks wrong, confirm the date range and status filters first.'
                    ],
                    'related' => [ 'Finance', 'Reports', 'Settings', 'Dashboard', 'Donations' ],
                ],
                'drive' => [
                    'module_name' => 'Drive and Files',
                    'description' => 'Drive combines storage location, sync state, search, and folder permissions so file work should start with the right folder context.',
                    'tags' => [ 'drive', 'files', 'folders', 'sync', 'documents' ],
                    'search_terms' => [ 'drive help', 'file search help', 'folder permission help' ],
                    'notes' => [
                        'Uploading to the right folder is as important as the file name itself.',
                        'Sync delays are possible, especially immediately after a change.',
                        'A missing file can be a folder visibility issue instead of an upload failure.'
                    ],
                    'issues' => [
                        'If a file is not visible, check search, folder access, and recent sync delay before uploading it again.',
                        'If a folder is missing, verify that the current user should have access to that location.'
                    ],
                    'related' => [ 'Drive', 'Search', 'Settings', 'People & Permissions', 'Troubleshooting' ],
                ],
                'calendar' => [
                    'module_name' => 'Calendar',
                    'description' => 'Calendar work depends on choosing the right calendar, the right audience, and the right event details before saving.',
                    'tags' => [ 'calendar', 'event', 'schedule', 'sharing', 'visibility' ],
                    'search_terms' => [ 'calendar help', 'event help', 'schedule help' ],
                    'notes' => [
                        'Board and public calendars should remain clearly separated.',
                        'Sharing controls who sees the event or calendar.',
                        'Event edits should be verified on the calendar after saving.'
                    ],
                    'issues' => [
                        'If an event is missing, confirm the user is looking at the right calendar first.',
                        'If a public event looks wrong, review visibility and wording before republishing or resharing it.'
                    ],
                    'related' => [ 'Calendar', 'Dashboard', 'Notifications', 'Reports', 'Settings' ],
                ],
                'reports' => [
                    'module_name' => 'Reports',
                    'description' => 'Reports are only as good as the filters, date range, and source workflow selected before the report is run or exported.',
                    'tags' => [ 'reports', 'filters', 'analytics', 'export', 'results' ],
                    'search_terms' => [ 'report help', 'filter help', 'export help' ],
                    'notes' => [
                        'Run the report after major filter changes so you can understand what changed.',
                        'Export only after the on-screen result looks correct.',
                        'Permission to a report usually follows permission to the underlying data.'
                    ],
                    'issues' => [
                        'If a report is empty, check filters and date ranges before assuming data is missing.',
                        'If another user cannot open a report, compare their role to the data area the report draws from.'
                    ],
                    'related' => [ 'Reports', 'Dashboard', 'Search', 'Donations', 'Finance' ],
                ],
                'security' => [
                    'module_name' => 'Security',
                    'description' => 'Security checks in Metis are designed to block unintended or unauthorized changes before they happen.',
                    'tags' => [ 'security', 'approval', 'enclave', 'nonce', 'permission' ],
                    'search_terms' => [ 'security help', 'approval help', 'permission denied help' ],
                    'notes' => [
                        'A blocked action is often working exactly as designed.',
                        'Refreshing an old page can resolve stale nonce failures.',
                        'Approval and permission are separate checks and both may matter.'
                    ],
                    'issues' => [
                        'If a protected action fails, read the exact security message before retrying it.',
                        'If approval is required, confirm the action scope instead of repeatedly clicking the same button.'
                    ],
                    'related' => [ 'Security', 'People & Permissions', 'Settings', 'Hermes', 'Troubleshooting' ],
                ],
                'hermes' => [
                    'module_name' => 'Hermes Assistant',
                    'description' => 'Hermes is designed to explain, guide, and diagnose within the same permission and approval boundaries that apply to the rest of Metis.',
                    'tags' => [ 'hermes', 'assistant', 'diagnostics', 'approval', 'guidance' ],
                    'search_terms' => [ 'hermes help', 'assistant help', 'diagnostic help' ],
                    'notes' => [
                        'Hermes can explain a process even when it cannot execute the protected action itself.',
                        'Clear prompts produce better results than vague prompts.',
                        'Sensitive work still requires normal permissions and approvals.'
                    ],
                    'issues' => [
                        'If Hermes seems too broad, add the module or outcome to your question.',
                        'If Hermes cannot perform the action, review approval or permission requirements instead of repeating the same request.'
                    ],
                    'related' => [ 'Hermes', 'Security', 'Search', 'Dashboard', 'Troubleshooting' ],
                ],
                'settings' => [
                    'module_name' => 'Settings',
                    'description' => 'Settings changes affect shared platform behavior, so they should be made intentionally and verified on the affected surface after saving.',
                    'tags' => [ 'settings', 'configuration', 'admin', 'module settings', 'platform' ],
                    'search_terms' => [ 'settings help', 'configuration help', 'admin settings help' ],
                    'notes' => [
                        'Shared settings can affect many users at once.',
                        'Use the most specific settings section available for the change.',
                        'Test the affected workflow after a major settings update.'
                    ],
                    'issues' => [
                        'If a setting seems ineffective, verify that you changed the correct settings section first.',
                        'If a save succeeds but behavior does not change, refresh the affected page or workflow before editing again.'
                    ],
                    'related' => [ 'Settings', 'System Administration', 'Accessibility', 'Security', 'Dashboard' ],
                ],
                'accessibility' => [
                    'module_name' => 'Accessibility',
                    'description' => 'Accessibility features help users tailor the interface to their reading, contrast, and navigation needs without changing core data workflows.',
                    'tags' => [ 'accessibility', 'readability', 'contrast', 'keyboard', 'low vision' ],
                    'search_terms' => [ 'accessibility help', 'readability help', 'keyboard help' ],
                    'notes' => [
                        'Small improvements in contrast, zoom, and focus handling can make a large difference over long sessions.',
                        'Search and filters reduce visual load on dense pages.',
                        'Keyboard access depends on visible focus states and deliberate navigation.'
                    ],
                    'issues' => [
                        'If a page feels overwhelming, narrow what you are viewing instead of forcing yourself to scan everything at once.',
                        'If text still feels too small, combine Metis accessibility options with browser zoom.'
                    ],
                    'related' => [ 'Accessibility', 'Settings', 'Dashboard', 'Search', 'Troubleshooting' ],
                ],
                'troubleshooting' => [
                    'module_name' => 'Troubleshooting',
                    'description' => 'Most routine Metis issues can be narrowed to visibility, validation, stale state, or workflow sequence before deeper investigation is needed.',
                    'tags' => [ 'troubleshooting', 'error', 'save failed', 'missing page', 'search issue' ],
                    'search_terms' => [ 'troubleshooting help', 'fix issue', 'metis problem' ],
                    'notes' => [
                        'Start with the smallest likely cause before escalating.',
                        'Repeated clicks rarely fix a permission or validation problem.',
                        'A fresh page load often resolves stale state and nonce issues.'
                    ],
                    'issues' => [
                        'If the symptom changes after a refresh, the original issue may have been stale session or stale page state.',
                        'If the same error affects many users, shift from user troubleshooting to system or settings review.'
                    ],
                    'related' => [ 'Troubleshooting', 'Search', 'Security', 'People & Permissions', 'System Administration' ],
                ],
                'system' => [
                    'module_name' => 'System Administration',
                    'description' => 'System administration work should be deliberate, auditable, and aligned with maintenance, logging, and recovery procedures.', 
                    'tags' => [ 'system administration', 'maintenance', 'logs', 'health', 'backup' ],
                    'search_terms' => [ 'system admin help', 'maintenance help', 'health check help' ],
                    'notes' => [
                        'Administrative actions should follow approved workflows.',
                        'Health checks and logs usually provide the next clue before deeper changes are made.',
                        'Recovery work should be documented and verified carefully.'
                    ],
                    'issues' => [
                        'If maintenance does not produce the expected result, review health and logs before rerunning the same action.',
                        'If a platform problem affects more than one area, check jobs, health, and recent system changes together.'
                    ],
                    'related' => [ 'System Administration', 'Settings', 'Security', 'Reports', 'Hermes' ],
                ],
            ];
        }
    }
}
