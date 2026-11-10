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
				__('To report something that is illegal where you live, use Report on its page or the <a href="{contact}">Contact page</a> (choose “Report content or a bug”), and say which law it breaks.', 'y4apk'),
				__('When we remove or limit your content or account, we tell you which rule or law it broke, unless the law or someone’s safety stops us. If you think we made a mistake, ask us to look again within 6 months through the <a href="{contact}">Contact page</a> (choose “Account help & appeals”). A person reviews it and tells you the result.', 'y4apk'),
			]],
			['id' => 'copyright', 't' => __('Copyright complaints', 'y4apk'), 'p' => [
				__('We respond to notices of copyright infringement as our <a href="{dmca}">DMCA Policy</a> describes. Owners can send a notice through the <a href="{copyright}">copyright form</a>. Accounts that infringe again and again are closed.', 'y4apk'),
			]],
			['id' => 'wallet', 't' => __('VIP, coins, gifts, paid content and creator earnings', 'y4apk'), 'p' => [
				__('<b>Paying:</b> coins are paid through a payment company on its own page; its terms apply to the payment. VIP is bought with coins. Digital purchases are used at once, so they are not refunded, except where the law says otherwise or a payment went wrong.', 'y4apk'),
				__('<b>Coins</b> are a limited licence to use features of the Service, such as VIP and gifts. They are not money, e-money or a bank account, have no cash value, earn no interest, and cannot be sold, cashed out, refunded or moved to another account except as gifts on the Service. We may change what coins buy and their prices for the future; coins you already have keep their number. Coins end if your account is closed for breaking these Terms, or if the Service closes (we give notice first where we can). Coins you had before they had a name keep their value: $1 of balance is 100 coins.', 'y4apk'),
				__('<b>Under 18?</b> If you are under 18, or under the age of adulthood where you live, ask a parent or guardian before buying coins or VIP. A parent who finds a purchase made without permission can write to us through the <a href="{contact}">Contact page</a>.', 'y4apk'),
				__('<b>Gifts</b> are sent with coins and cannot be taken back. A creator gets the value of a gift less the site’s share. Gifts to yourself, or between accounts one person controls, are not allowed: we refuse gifts to a creator from accounts less than a day old or from the creator’s own connection, and limit how many coins one member can give in a day.', 'y4apk'),
				__('<b>Paid scripts and files</b> are sold for coins by the creators who made them. Unlocking one gives you a personal licence to use it yourself: you may not share it, sell it again or post it anywhere else. If it does not work or is not what it says, you can ask for your coins back within 7 days of unlocking (unless the page says otherwise), and one taken off the site in that time is refunded by itself. VIP members pay less, as the page shows.', 'y4apk'),
				__('<b>Selling:</b> a creator may sell only their own work — a script they wrote, or an app or tool they made — that they have the right to sell. A game or app from Google Play, a mod of one, or anybody else’s script may not be sold. The site keeps the share shown when you set the price. What breaks this is taken off and refunded, and the account can be closed.', 'y4apk'),
				__('<b>Creator earnings</b> (from gifts, sales and counted downloads) are open to the creators we choose (for example by followers) and may change or stop at any time for future earnings. Downloads are counted by our own rules, once a day per device. Earnings from gifts and sales can be withdrawn after a waiting period (14 days unless the page says otherwise), so stolen cards, reversed payments and refunds can be dealt with first. Earnings made by fraud — fake downloads or accounts, gifting yourself, or anything that breaks these Terms — can be held or cancelled. Withdrawals are paid by hand, usually within a few days, by the ways offered; you give correct details and are responsible for any taxes on what you earn.', 'y4apk'),
			]],
			['id' => 'ads', 't' => __('Ads and other websites', 'y4apk'), 'p' => [
				__('The Service shows ads and links to other websites, and some links open them in a new tab. We don’t control those websites and aren’t responsible for them: their own terms and policies apply.', 'y4apk'),
			]],
			['id' => 'service', 't' => __('The Service itself', 'y4apk'), 'p' => [
				__('The Y4APK name, logo, design and the text we write belong to us. Don’t copy the site, or use our name in a way that suggests we work with you, without our permission.', 'y4apk'),
				__('Some text on Y4APK, such as parts of game and app pages and the translations into other languages, is written with the help of AI and may contain mistakes. If you find one, tell us through <a href="{feedback}">Feedback</a>.', 'y4apk'),
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
				__('Y4APK decides how and why this information is used, so we are responsible for it (in some laws, the “controller”). You can reach us about it through the <a href="{contact}">Contact page</a>.', 'y4apk'),
			]],
			['id' => 'give', 't' => __('Information you give us', 'y4apk'), 'li' => [
				__('<b>Account details:</b> your username, email address and password. We store your password only in scrambled (hashed) form: nobody can read it, not even us.', 'y4apk'),
				__('<b>Your profile:</b> the name, picture, banner, bio and links you add, and a Roblox username if you link one.', 'y4apk'),
				__('<b>Signing in with Google or Discord:</b> if you choose it, that company tells us your account’s id there and your email address (and that the email is confirmed). We keep only the id, to know you next time, and the email; not your name, picture, contacts or anything else, and no access to your account there. You can disconnect it in Account settings.', 'y4apk'),
				__('<b>What you share:</b> scripts, files, versions, descriptions, comments and reports, with the time you shared them.', 'y4apk'),
				__('<b>Messages between members:</b> what you write to other members, kept encrypted, and when. Only you and the member you write to can read it; the site’s team reads a message only when one of you reports it (with the few before it). Unsent messages and closed accounts’ conversations are deleted. We may email you that someone wrote to you, never what they wrote; you can turn that off.', 'y4apk'),
				__('<b>Messages to us:</b> what you send through the Contact, Feedback and copyright forms, with the name and email you give.', 'y4apk'),
				__('<b>Payments and your wallet:</b> when you pay, the payment company handles it on its own page; we keep the order (what, how much, its state and the payment’s reference), never card details or wallet keys. We keep your coins and their history, and the gifts you send with the name and message you choose (gifts to the site may be shown under “Thanks to”). To stop members gifting themselves, we compare a one-way hash of the connection you use with the creator’s; the address itself is not kept. If you ask to withdraw creator earnings, we keep the way and the details you give (such as a wallet address or phone number), encrypted, to pay you.', 'y4apk'),
			]],
			['id' => 'auto', 't' => __('Information collected automatically', 'y4apk'), 'li' => [
				__('<b>When you make an account:</b> your country (from your network, or guessed from your browser’s language), the kind of device, its system and browser (not the full browser string), the website that sent you (its name only) and the first page of your visit. This tells us how people find Y4APK.', 'y4apk'),
				__('<b>Statistics:</b> views, downloads and clicks are counted per page as daily totals. They are not linked to you and don’t include your IP address.', 'y4apk'),
				__('<b>Downloads that pay creators:</b> so a device counts once a day, we keep a one-way code made from your network address and browser with a key that changes every day and is then thrown away. It cannot be turned back into your address, we don’t store the address itself, and the codes are deleted after two days. With each counted download we keep only the country it came from (two letters, from our network provider), to pay creators by country.', 'y4apk'),
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
				__('Where the law asks for your consent first (for example in the EEA, the UK and Switzerland), these analytics and advertising cookies are used only if you agree, and you can change your choice at any time.', 'y4apk'),
			]],
			['id' => 'use', 't' => __('How we use information', 'y4apk'), 'li' => [
				__('To run your account and the site, and show your profile and what you share.', 'y4apk'),
				__('To send the emails you need or asked for: confirmations, password resets, review results, and news about what you follow (you can turn these off in Account settings).', 'y4apk'),
				__('To keep Y4APK safe: checking uploaded files, stopping spam, fraud and abuse, and enforcing our Terms.', 'y4apk'),
				__('To understand which pages and features are used, from totals, and make the site better.', 'y4apk'),
				__('To answer your messages, and to meet legal duties.', 'y4apk'),
			], 'after' => [
				__('Where the law asks for a legal basis (for example in the EU and UK), we rely on running the Service you asked for, our legitimate interests in keeping it safe and working, your consent (for notifications and optional cookies, which you can withdraw), and legal obligations.', 'y4apk'),
				__('Automatic checks look at what you upload (for example for harmful or broken files) and may hold it back until a person looks at it. They don’t make decisions about you that have legal or similarly important effects.', 'y4apk'),
			]],
			['id' => 'public', 't' => __('What other people can see', 'y4apk'), 'p' => [
				__('Your profile is public: your username, name, picture, banner, bio, links, when you joined, who you follow and who follows you, and what you share. Your email address, password, settings and the sign-up information above are never shown.', 'y4apk'),
			]],
			['id' => 'share', 't' => __('How we share information', 'y4apk'), 'p' => [
				__('We don’t sell or rent your personal information. We share it only:', 'y4apk'),
			], 'li' => [
				__('With service providers that work for us: hosting, content delivery and file storage, email delivery, security and bot checks, and analytics. They may use it only to provide their service to us.', 'y4apk'),
				__('With AI services that help write the page of an upload, using what you wrote about it; never your email or password.', 'y4apk'),
				__('With AI services that check a script you share before it goes live (its name, game, description and code, and the code it loads); email addresses are taken out first, and your account details are never sent.', 'y4apk'),
				__('When the law requires it, or to protect the rights, safety and property of our users, the public or Y4APK.', 'y4apk'),
				__('If Y4APK is reorganised, merged or sold, as part of that change, under this policy.', 'y4apk'),
				__('With your permission.', 'y4apk'),
			], 'after' => [
				__('Our advertising partners may use cookies to show ads based on your interests, which some US state laws call “selling” or “sharing” information. You can turn off personalised ads in your Google ad settings, or block these cookies in your browser.', 'y4apk'),
			]],
			['id' => 'transfers', 't' => __('International transfers', 'y4apk'), 'p' => [
				__('Our providers may process information in countries other than yours. When they do, we use the protections the law requires.', 'y4apk'),
			]],
			['id' => 'keep', 't' => __('How long we keep information', 'y4apk'), 'li' => [
				__('Account information: while your account is open. When you close it, it is deleted within 30 days, except what the law asks us to keep or what stays a short time in backups.', 'y4apk'),
				__('What you share: until you or we remove it.', 'y4apk'),
				__('Messages to us: as long as needed to answer them, and up to two years.', 'y4apk'),
				__('Statistics: as totals, which say nothing about you.', 'y4apk'),
				__('Orders, your wallet’s history and withdrawals: as long as your account is open and then as long as tax and accounting laws require.', 'y4apk'),
			]],
			['id' => 'security', 't' => __('Security', 'y4apk'), 'p' => [
				__('We protect information with encryption in transit (HTTPS), scrambled passwords, limited access and checks against abuse. No method is perfect, so please use a strong password that you don’t use anywhere else.', 'y4apk'),
				__('If a security problem puts your information at risk, we tell you, and the authorities where the law asks us to.', 'y4apk'),
			]],
			['id' => 'rights', 't' => __('Your rights and choices', 'y4apk'), 'p' => [
				__('Depending on where you live, you can ask to see, correct, export or delete your personal information, object to or limit some uses, and withdraw consent. Many changes you can make yourself in <a href="{settings}">Account settings</a>; for the rest, send a <a href="{contact}">privacy request</a>. We may ask you to confirm it is really you. You can also complain to the data protection authority where you live.', 'y4apk'),
				__('We answer within the time the law where you live sets, usually within 30 days, and we don’t treat you differently for using these rights.', 'y4apk'),
			]],
			['id' => 'children', 't' => __('Children', 'y4apk'), 'p' => [
				__('Y4APK is not for children under 13. We don’t knowingly collect their information; if we learn we have, we delete it.', 'y4apk'),
				__('Are you a parent who thinks your child gave us information? Write to us through the <a href="{contact}">Contact page</a>, and we will delete it.', 'y4apk'),
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
				__('Your name, address, phone number, email address and signature.', 'y4apk'),
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
				__('For trademarks, privacy, content that is illegal where you live (for example under the EU Digital Services Act), or anything else, use the <a href="{contact}">Contact page</a>. If a translation differs from the English version, the English version applies.', 'y4apk'),
			]],
		];
	}

	/**
	 * The FAQ (2.53): how Y4APK works, in groups. A group: ['id', 't' => title, 'icon', 'q' => [[anchor, question, answer], …]].
	 * Answers may also use {executors}, {share}, {forgot}, {register} and {obb} (the site's own "Install OBB" guide, when it has one).
	 */
	public static function faq() {
		$obb = self::guide();
		return [
			['id' => 'install', 't' => __('Downloading and installing', 'y4apk'), 'icon' => 'download', 'q' => [
				['free', __('Is downloading from Y4APK free?', 'y4apk'), __('Yes. You don’t need an account to browse or download. The ads on the site pay for the hosting and the downloads.', 'y4apk')],
				['apk', __('How do I install an APK file?', 'y4apk'), __('Download it, open it from your notifications or your Files app, and tap Install. If Android asks, allow “Install unknown apps” for your browser or file manager. You can turn it off again afterwards.', 'y4apk')],
				['obb', __('The game has an OBB file. How do I install it?', 'y4apk'), __('Install the APK first, but don’t open the game yet. Unzip the OBB, move the folder named after the game (such as com.company.game) to <b>Android/obb</b> on your phone, then open the game.', 'y4apk')
					. ($obb ? ' ' . __('There are pictures of every step in our <a href="{obb}">OBB guide</a>.', 'y4apk') : '')],
				['xapk', __('What are XAPK and APKS files?', 'y4apk'), __('They hold an app in several parts, and sometimes its OBB too. Tapping them doesn’t install them: use an installer for split APKs, such as SAI.', 'y4apk')],
				['failed', __('It says “App not installed”. What can I do?', 'y4apk'), __('Check that your phone has the Android version the page asks for and enough free space. If the same app is already installed from somewhere else, uninstall it first: Android only updates an app with the same signature.', 'y4apk')],
				['progress', __('Will I lose my progress if I install a MOD?', 'y4apk'), __('Often the original has to be uninstalled first, and that deletes what is saved only on the phone. If the game lets you, link it to an account or back it up before you change versions.', 'y4apk')],
				['link', __('A download doesn’t start or the file is broken.', 'y4apk'), __('Try again in a few minutes, or use another link on the page if there is one. Still not working? Tell us through the <a href="{contact}">Contact page</a> (choose “Report content or a bug”) and add the page’s link.', 'y4apk')],
			]],
			['id' => 'mods', 't' => __('MODs and safety', 'y4apk'), 'icon' => 'shield', 'q' => [
				['mod', __('What is a MOD APK?', 'y4apk'), __('A version of a game or app changed by someone other than its maker, for example with items unlocked or without ads. What a MOD changes is listed on its page under MOD information.', 'y4apk')],
				['safe', __('Are the files safe?', 'y4apk'), __('Shared files are checked automatically, and members report problems. No check can promise that a file is completely safe, so install only what you trust and keep your phone’s protection switched on.', 'y4apk')],
				['ban', __('Can I get banned for using a MOD or a script?', 'y4apk'), __('Yes, it can happen. Many games don’t allow MODs or scripts and can ban accounts that use them, especially in online games. Use them at your own risk, on an account you can lose.', 'y4apk')],
				['official', __('Is Y4APK part of Google Play or Roblox?', 'y4apk'), __('No. Y4APK is independent. The names and logos of games and apps belong to their owners.', 'y4apk')],
			]],
			['id' => 'scripts', 't' => __('Roblox scripts and executors', 'y4apk'), 'icon' => 'code', 'q' => [
				['use', __('How do I use a Roblox script?', 'y4apk'), __('You need an executor: an app that runs scripts in Roblox. Pick one on the <a href="{executors}">Executors</a> page and install it. Open Roblox, then the executor, copy the script from its page on Y4APK, paste it and press Execute.', 'y4apk')],
				['key', __('What is a key system?', 'y4apk'), __('Some scripts and executors ask for a free key before they run, which you get from their maker’s page. Y4APK marks them as Key system or Keyless.', 'y4apk')],
				['broken', __('A script stopped working.', 'y4apk'), __('Roblox updates often break scripts and executors for a while. Check that your executor is marked as working on the <a href="{executors}">Executors</a> page, look for a newer script, or report it on its page.', 'y4apk')],
			]],
			['id' => 'account', 't' => __('Your account and sharing', 'y4apk'), 'icon' => 'user', 'q' => [
				['need', __('Do I need an account?', 'y4apk'), __('Not to browse or download. A free account lets you share games, apps and scripts, follow members and hear about their new uploads. <a href="{register}">Make an account</a>.', 'y4apk')],
				['share', __('How do I share a game, app or script?', 'y4apk'), __('Sign in and open <a href="{share}">Share</a>, choose what you share, add the file or the script and its details, and send it. Files can be up to 10 GB.', 'y4apk')],
				['review', __('When does my upload go live?', 'y4apk'), __('Many uploads go live as soon as the automatic checks pass. Others wait for a short review. You get an email with the result, and if something needs changing, it says what.', 'y4apk')],
				['settings', __('How do I change my details or close my account?', 'y4apk'), __('In <a href="{settings}">Account settings</a> you can change your name, picture, password and emails, or close your account. What happens to your information is in our <a href="{privacy}">Privacy Policy</a>.', 'y4apk')],
				['password', __('I forgot my password.', 'y4apk'), __('Use <a href="{forgot}">Forgot password?</a> on the sign-in page, and we email you a link to make a new one. Nobody from Y4APK will ever ask you for your password.', 'y4apk')],
			]],
			['id' => 'site', 't' => __('Languages and the app', 'y4apk'), 'icon' => 'globe', 'q' => [
				['language', __('How do I change the language or location?', 'y4apk'), __('Use the globe button at the top of the page, or the language row in your account menu. Pages in other languages are translated automatically, so if a word is wrong, tell us through <a href="{feedback}">Feedback</a>.', 'y4apk')],
				['app', __('Can I install Y4APK as an app?', 'y4apk'), __('Yes. In Chrome, choose Install app or Add to Home screen. On iPhone, tap Share, then “Add to Home Screen”.', 'y4apk')],
			]],
			['id' => 'reports', 't' => __('Copyright and reports', 'y4apk'), 'icon' => 'copyright', 'q' => [
				['owner', __('My game or app is on Y4APK without my permission.', 'y4apk'), __('Send a notice through the <a href="{copyright}">copyright form</a>. We usually act within a few working days, as our <a href="{dmca}">DMCA Policy</a> explains.', 'y4apk')],
				['report', __('How do I report a file, a script or a member?', 'y4apk'), __('Use Report on its page, or the <a href="{contact}">Contact page</a> (choose “Report content or a bug”). The member doesn’t see who reported it.', 'y4apk')],
			]],
		];
	}

	/** The site's own "Install OBB" page, when it has one. */
	public static function guide() {
		foreach (['install-obb', 'how-to-install-obb', 'obb'] as $slug) {
			$p = get_page_by_path($slug, OBJECT, 'page');
			if ($p && $p->post_status === 'publish') {
				return get_permalink($p);
			}
		}
		return '';
	}

	/**
	 * The questions added in Appearance ▸ Customize ▸ FAQ, after the theme's own: [[question, answer HTML], …].
	 * The theme's old sample questions are left out, and so is one the FAQ already answers (installing an OBB).
	 */
	public static function own_faq() {
		$items = get_theme_mod('faqs', []);
		$sample = ['is worth to buy y4apk theme from y4apk?', 'why themes are costlier in y4apk?', 'why other websites sold themes for low cost?'];
		$out = [];
		foreach (is_array($items) ? $items : [] as $it) {
			$q = trim(wp_strip_all_tags((string) ($it['question'] ?? '')));
			$a = trim((string) ($it['answer'] ?? ''));
			if ($q === '' || $a === '' || in_array(strtolower($q), $sample, true) || preg_match('~\bobb\b~i', $q)) {
				continue;
			}
			$out[] = [$q, $a];
		}
		return $out;
	}
}
