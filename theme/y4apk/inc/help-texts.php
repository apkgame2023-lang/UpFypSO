<?php
/**
 * The words of the Terms of Service, the Privacy Policy and the DMCA Policy
 * (2.52), written for what this site really does. Each paragraph is its own
 * text, so Y4APK ▸ Languages translates them like the rest of the site; the
 * pages say the English version is the one that counts.
 *
 * A section: ['id' => anchor, 't' => title, 'p' => [paragraphs], 'li' => [list items], 'after' => [paragraphs after the list]].
 * Paragraphs may hold <b>, <a href="{terms}"> … {terms}, {privacy}, {dmca},
 * {copyright}, {contact}, {feedback}, {settings} are replaced by the pages' addresses.
 */

defined('ABSPATH') || exit;

class Y4APK_Help_Texts {

	/** "The short version": [title, text] × 4. */
	public static function short($page) {
		switch ($page) {
			case 'terms':
				return [
					[__('Be 13 or older and keep your account safe.', 'y4apk'), __('You are responsible for what happens in it.', 'y4apk')],
					[__('You own what you share.', 'y4apk'), __('You let us host it and show it on Y4APK, so others can download and use it.', 'y4apk')],
					[__('Files come from members and other sites.', 'y4apk'), __('Check what you download, and install it at your own risk.', 'y4apk')],
					[__('No malware, scams or stolen accounts.', 'y4apk'), __('We remove it and may close the accounts that post it.', 'y4apk')],
				];
			case 'privacy':
				return [
					[__('We don’t sell your personal information.', 'y4apk'), __('We share it only in the ways this policy describes.', 'y4apk')],
					[__('Our statistics don’t keep your IP address.', 'y4apk'), __('Views and downloads are counted as daily totals.', 'y4apk')],
					[__('Your email is never shown.', 'y4apk'), __('Others see only your public profile and what you share.', 'y4apk')],
					[__('You’re in control.', 'y4apk'), __('Ask for a copy of your data, a correction, or to delete it.', 'y4apk')],
				];
			case 'dmca':
				return [
					[__('We respect copyright.', 'y4apk'), __('We remove material when its owner tells us it is shared without permission.', 'y4apk')],
					[__('Send one complete notice.', 'y4apk'), __('The copyright form asks for everything the law needs.', 'y4apk')],
					[__('Uploaders can answer.', 'y4apk'), __('If a removal was a mistake, a counter-notice can bring it back.', 'y4apk')],
					[__('Repeat infringers lose their accounts.', 'y4apk'), __('Sharing others’ work again and again closes an account.', 'y4apk')],
				];
		}
		return [];
	}

	public static function sections($page) {
		return $page === 'terms' ? self::terms() : ($page === 'privacy' ? self::privacy() : ($page === 'dmca' ? self::dmca() : []));
	}

	private static function terms() {
		return [
			['id' => 'who', 't' => __('Who can use Y4APK', 'y4apk'), 'p' => [
				__('Welcome to Y4APK. These Terms of Service (“Terms”) are the rules for using the website y4apk.com and everything on it: game and app pages, downloads, Roblox scripts, accounts and profiles (together, the “Service”). By using the Service you agree to these Terms and to our <a href="{privacy}">Privacy Policy</a>. If you don’t agree, please don’t use the Service.', 'y4apk'),
				__('You must be at least 13 years old, or older if the law where you live requires it. If you are under the age of adulthood where you live, a parent or guardian must agree to these Terms for you.', 'y4apk'),
			]],
			['id' => 'account', 't' => __('Your account', 'y4apk'), 'p' => [
				__('You don’t need an account to browse or download. To share, follow or report, you make a free account.', 'y4apk'),
			], 'li' => [
				__('Give a real email address and keep it up to date: it is how we confirm your account and reach you.', 'y4apk'),
				__('Keep your password secret. You are responsible for everything done with your account.', 'y4apk'),
				__('One person per account. Don’t sell, share or lend it, and don’t make accounts to get around a ban.', 'y4apk'),
				__('You can change your details or close your account at any time in <a href="{settings}">Account settings</a>.', 'y4apk'),
			]],
			['id' => 'content', 't' => __('What you share', 'y4apk'), 'p' => [
				__('You keep the rights you have in what you share: scripts, files, versions, descriptions, pictures and your profile (“your content”).', 'y4apk'),
				__('So that we can run the Service, you give Y4APK a worldwide, free, non-exclusive licence to host, store, copy, show, distribute and adapt your content (for example to make pictures smaller, to write its page or to translate its page), only to run, show and improve the Service. The licence ends when you or we remove the content, apart from copies that take a short time to clear from caches and backups.', 'y4apk'),
				__('You promise that you have the right to share your content, and that sharing it does not break the law or anyone else’s rights. Our automatic checks and reviews look for problems, but passing them does not make us responsible for your content.', 'y4apk'),
			]],
			['id' => 'downloads', 't' => __('Downloads, apps and scripts', 'y4apk'), 'p' => [
				__('Files on Y4APK come from members and from other websites. MOD versions are changed by people who are not the apps’ makers. Y4APK is not part of Google Play, Roblox or the developers of the games and apps listed, and their names and logos belong to their owners.', 'y4apk'),
				__('Check files before you open them and install them at your own risk. Scripts and MODs may break the rules of a game or service and can get an account banned there: following those rules is up to you.', 'y4apk'),
			]],
			['id' => 'rules', 't' => __('Rules for content and conduct', 'y4apk'), 'p' => [
				__('Don’t share, link to or do any of the following on Y4APK:', 'y4apk'),
			], 'li' => [
				__('Malware, viruses, spyware, hidden crypto miners, or anything that harms devices or data.', 'y4apk'),
				__('Scams, phishing, keyloggers, or scripts and files that steal accounts, items or money.', 'y4apk'),
				__('Material you have no right to share, or that infringes someone’s copyright or trademark.', 'y4apk'),
				__('Sexual content involving minors, threats, hate, harassment or violent content.', 'y4apk'),
				__('Spam, fake reports, or tricks to inflate views, downloads or followers.', 'y4apk'),
				__('Pretending to be someone else, or sharing other people’s private information.', 'y4apk'),
				__('Attacking, overloading or scraping the Service, or getting around its limits and checks.', 'y4apk'),
			]],
			['id' => 'moderation', 't' => __('Reports and moderation', 'y4apk'), 'p' => [
				__('Anyone can report content. We may review, hide, remove or limit content, stop it from going live at once, or suspend or close accounts that break these Terms, with or without notice. Where we can, we tell you why.', 'y4apk'),
				__('If you think we made a mistake, tell us through the <a href="{contact}">Contact page</a>.', 'y4apk'),
			]],
			['id' => 'copyright', 't' => __('Copyright complaints', 'y4apk'), 'p' => [
				__('We respond to notices of copyright infringement as our <a href="{dmca}">DMCA Policy</a> describes. Owners can send a notice through the <a href="{copyright}">copyright form</a>. Accounts that infringe again and again are closed.', 'y4apk'),
			]],
			['id' => 'ads', 't' => __('Ads and other websites', 'y4apk'), 'p' => [
				__('The Service shows ads and links to other websites, and some links open them in a new tab. We don’t control those websites and aren’t responsible for them: their own terms and policies apply.', 'y4apk'),
			]],
			['id' => 'service', 't' => __('The Service itself', 'y4apk'), 'p' => [
				__('The Y4APK name, logo, design and the text we write belong to us. Don’t copy the site, or use our name in a way that suggests we work with you, without our permission.', 'y4apk'),
				__('We keep improving the Service, so features may change, pause or end.', 'y4apk'),
			]],
			['id' => 'disclaimers', 't' => __('Disclaimers', 'y4apk'), 'p' => [
				__('The Service and everything on it are provided “as is” and “as available”, without warranties of any kind, as far as the law allows. We don’t promise that files are safe, work on your device, or are always available.', 'y4apk'),
			]],
			['id' => 'liability', 't' => __('Limitation of liability', 'y4apk'), 'p' => [
				__('As far as the law allows, Y4APK is not liable for indirect, incidental or consequential damages, or for lost data, damage to devices, or accounts banned elsewhere, that come from using the Service or anything downloaded from it. Nothing in these Terms limits rights you have by law that cannot be limited.', 'y4apk'),
			]],
			['id' => 'ending', 't' => __('Ending your use of Y4APK', 'y4apk'), 'p' => [
				__('You can stop using the Service and close your account at any time. We may suspend or end your access if you break these Terms or if we have to by law. The parts of these Terms that by their nature should last (such as the licence for content already shared, disclaimers and limits of liability) still apply.', 'y4apk'),
			]],
			['id' => 'changes', 't' => __('Changes to these Terms', 'y4apk'), 'p' => [
				__('We may update these Terms. The date at the top shows the last change, and we point out important changes on the site. If you keep using the Service after a change, you accept the new Terms.', 'y4apk'),
			]],
			['id' => 'contact', 't' => __('Translations and questions', 'y4apk'), 'p' => [
				__('These Terms may be shown in other languages. If a translation differs from the English version, the English version applies.', 'y4apk'),
				__('Questions about these Terms? Write to us through the <a href="{contact}">Contact page</a>.', 'y4apk'),
			]],
		];
	}

	private static function privacy() {
		return [
			['id' => 'intro', 't' => __('About this policy', 'y4apk'), 'p' => [
				__('This Privacy Policy explains what information Y4APK (the website y4apk.com, “we”) collects when you use it, why, who can see it, and the choices you have. It goes together with our <a href="{terms}">Terms of Service</a>.', 'y4apk'),
			]],
			['id' => 'give', 't' => __('Information you give us', 'y4apk'), 'li' => [
				__('<b>Account details:</b> your username, email address and password. We store your password only in scrambled (hashed) form: nobody can read it, not even us.', 'y4apk'),
				__('<b>Your profile:</b> the name, picture, banner, bio and links you add, and a Roblox username if you link one.', 'y4apk'),
				__('<b>What you share:</b> scripts, files, versions, descriptions, comments and reports, with the time you shared them.', 'y4apk'),
				__('<b>Messages to us:</b> what you send through the Contact, Feedback and copyright forms, with the name and email you give.', 'y4apk'),
			]],
			['id' => 'auto', 't' => __('Information collected automatically', 'y4apk'), 'li' => [
				__('<b>When you make an account:</b> your country (from your network, or guessed from your browser’s language), the kind of device, its system and browser (not the full browser string), the website that sent you (its name only) and the first page of your visit. This tells us how people find Y4APK.', 'y4apk'),
				__('<b>Statistics:</b> views, downloads and clicks are counted per page as daily totals. They are not linked to you and don’t include your IP address.', 'y4apk'),
				__('<b>To deliver the site and stop abuse:</b> our hosting and security providers process your IP address and similar network information, and keep standard server logs for a short time. We use it briefly to limit repeated attempts (such as many sign-ins or messages).', 'y4apk'),
			]],
			['id' => 'cookies', 't' => __('Cookies and similar technologies', 'y4apk'), 'p' => [
				__('We use a few cookies and your browser’s storage to make the site work:', 'y4apk'),
			], 'li' => [
				__('Sign-in cookies that keep you signed in, and one that shows your name and picture in the header.', 'y4apk'),
				__('Your language and location choices.', 'y4apk'),
				__('In your browser only: recent searches, the progress of an upload, and whether you closed a message.', 'y4apk'),
				__('If you turn on update notifications, your browser gives us an address to send them to. You can turn them off in your browser at any time.', 'y4apk'),
			], 'after' => [
				__('Analytics (such as Google Analytics) and advertising partners may set cookies of their own to measure visits and show ads. You can block or delete cookies in your browser; some parts of the site may then not work.', 'y4apk'),
			]],
			['id' => 'use', 't' => __('How we use information', 'y4apk'), 'li' => [
				__('To run your account and the site, and show your profile and what you share.', 'y4apk'),
				__('To send the emails you need or asked for: confirmations, password resets, review results, and news about what you follow (you can turn these off in Account settings).', 'y4apk'),
				__('To keep Y4APK safe: checking uploaded files, stopping spam, fraud and abuse, and enforcing our Terms.', 'y4apk'),
				__('To understand which pages and features are used, from totals, and make the site better.', 'y4apk'),
				__('To answer your messages, and to meet legal duties.', 'y4apk'),
			], 'after' => [
				__('Where the law asks for a legal basis (for example in the EU and UK), we rely on running the Service you asked for, our legitimate interests in keeping it safe and working, your consent (for notifications and optional cookies, which you can withdraw), and legal obligations.', 'y4apk'),
			]],
			['id' => 'public', 't' => __('What other people can see', 'y4apk'), 'p' => [
				__('Your profile is public: your username, name, picture, banner, bio, links, when you joined, who you follow and who follows you, and what you share. Your email address, password, settings and the sign-up information above are never shown.', 'y4apk'),
			]],
			['id' => 'share', 't' => __('How we share information', 'y4apk'), 'p' => [
				__('We don’t sell or rent your personal information. We share it only:', 'y4apk'),
			], 'li' => [
				__('With service providers that work for us: hosting, content delivery and file storage, email delivery, security and bot checks, and analytics. They may use it only to provide their service to us.', 'y4apk'),
				__('With AI services that help write the page of an upload, using what you wrote about it; never your email or password.', 'y4apk'),
				__('When the law requires it, or to protect the rights, safety and property of our users, the public or Y4APK.', 'y4apk'),
				__('If Y4APK is reorganised, merged or sold, as part of that change, under this policy.', 'y4apk'),
				__('With your permission.', 'y4apk'),
			]],
			['id' => 'transfers', 't' => __('International transfers', 'y4apk'), 'p' => [
				__('Our providers may process information in countries other than yours. When they do, we use the protections the law requires.', 'y4apk'),
			]],
			['id' => 'keep', 't' => __('How long we keep information', 'y4apk'), 'li' => [
				__('Account information: while your account is open. When you close it, it is deleted within 30 days, except what the law asks us to keep or what stays a short time in backups.', 'y4apk'),
				__('What you share: until you or we remove it.', 'y4apk'),
				__('Messages to us: as long as needed to answer them, and up to two years.', 'y4apk'),
				__('Statistics: as totals, which say nothing about you.', 'y4apk'),
			]],
			['id' => 'security', 't' => __('Security', 'y4apk'), 'p' => [
				__('We protect information with encryption in transit (HTTPS), scrambled passwords, limited access and checks against abuse. No method is perfect, so please use a strong password that you don’t use anywhere else.', 'y4apk'),
			]],
			['id' => 'rights', 't' => __('Your rights and choices', 'y4apk'), 'p' => [
				__('Depending on where you live, you can ask to see, correct, export or delete your personal information, object to or limit some uses, and withdraw consent. Many changes you can make yourself in <a href="{settings}">Account settings</a>; for the rest, send a <a href="{contact}">privacy request</a>. We may ask you to confirm it is really you. You can also complain to the data protection authority where you live.', 'y4apk'),
			]],
			['id' => 'children', 't' => __('Children', 'y4apk'), 'p' => [
				__('Y4APK is not for children under 13. We don’t knowingly collect their information; if we learn we have, we delete it.', 'y4apk'),
			]],
			['id' => 'changes', 't' => __('Changes to this policy', 'y4apk'), 'p' => [
				__('We may update this policy. The date at the top shows the last change, and we point out important changes on the site. If a translation differs from the English version, the English version applies.', 'y4apk'),
			]],
			['id' => 'contact', 't' => __('Contact', 'y4apk'), 'p' => [
				__('Questions or requests about your privacy? Use the <a href="{contact}">Contact page</a> and choose “Privacy or data request”.', 'y4apk'),
			]],
		];
	}

	private static function dmca() {
		return [
			['id' => 'approach', 't' => __('Our approach', 'y4apk'), 'p' => [
				__('Y4APK hosts pages and files, many of them shared by members. We respect the rights of copyright owners and respond to notices of claimed infringement under the U.S. Digital Millennium Copyright Act (17 U.S.C. § 512) and similar laws elsewhere.', 'y4apk'),
			]],
			['id' => 'notice', 't' => __('How to send a notice', 'y4apk'), 'p' => [
				__('The quickest way is the <a href="{copyright}">copyright form</a>. One notice can list many links. A notice must include:', 'y4apk'),
			], 'li' => [
				__('Your physical or electronic signature (typing your full name is enough).', 'y4apk'),
				__('The copyrighted work you say is infringed, such as the official game or app and where it can be found.', 'y4apk'),
				__('The exact addresses (URLs) on Y4APK of the material to remove.', 'y4apk'),
				__('Your name and an email address where we can reach you.', 'y4apk'),
				__('A statement that you believe in good faith that the use is not allowed by the owner, its agent or the law.', 'y4apk'),
				__('A statement that the notice is accurate and, under penalty of perjury, that you are the owner or allowed to act for the owner.', 'y4apk'),
			], 'after' => [
				__('Incomplete notices may be delayed while we ask for what is missing.', 'y4apk'),
			]],
			['id' => 'next', 't' => __('What happens next', 'y4apk'), 'p' => [
				__('We review each notice, usually within a few working days, and remove or disable access to the material when the notice is complete. We may tell the uploader and give them a copy of the notice, without your email address.', 'y4apk'),
			]],
			['id' => 'counter', 't' => __('Counter-notices', 'y4apk'), 'p' => [
				__('If your material was removed and you believe it was a mistake or you have the right to share it, send a counter-notice through the <a href="{contact}">Contact page</a> (choose “Copyright notice”) with:', 'y4apk'),
			], 'li' => [
				__('Your name, email address and signature.', 'y4apk'),
				__('The material that was removed and where it appeared on Y4APK.', 'y4apk'),
				__('A statement under penalty of perjury that you believe in good faith it was removed by mistake or misidentification.', 'y4apk'),
				__('Your consent to the jurisdiction of the courts where you live (or, outside the U.S., any place where Y4APK may be found), and that you accept service from the person who sent the notice.', 'y4apk'),
			], 'after' => [
				__('We may put the material back after 10 to 14 working days, unless the person who sent the notice tells us they have gone to court.', 'y4apk'),
			]],
			['id' => 'repeat', 't' => __('Repeat infringers', 'y4apk'), 'p' => [
				__('We close the accounts of members who infringe copyright again and again.', 'y4apk'),
			]],
			['id' => 'false', 't' => __('False notices', 'y4apk'), 'p' => [
				__('Anyone who knowingly makes false claims in a notice or counter-notice may be liable for damages. If you are not sure that material infringes your rights, please get advice first.', 'y4apk'),
			]],
			['id' => 'other', 't' => __('Other complaints', 'y4apk'), 'p' => [
				__('For trademarks, privacy, or anything else, use the <a href="{contact}">Contact page</a>. If a translation differs from the English version, the English version applies.', 'y4apk'),
			]],
		];
	}
}
