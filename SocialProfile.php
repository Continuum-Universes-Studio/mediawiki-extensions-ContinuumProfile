<?php
/**
 * Protect against register_globals vulnerabilities.
 * This line must be present before any global variable is referenced.
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die(
		'This is the setup file for the SocialProfile extension to MediaWiki.' .
		'Please see https://continuum-universes-wiki.com/Extension:ContinuumProfile for' .
		' more information about this extension.'
	);
}

/**
 * This is the loader file for the SocialProfile extension. You should include
 * this file in your wiki's LocalSettings.php to activate SocialProfile.
 *
 * If you want to use the UserWelcome extension (bundled with SocialProfile),
 * the <topusers /> tag or the user levels feature, there are some other files
 * you will need to include in LocalSettings.php. The online manual has more
 * details about this.
 *
 * For more info about SocialProfile, please see https://www.mediawiki.org/wiki/Extension:SocialProfile.
 */

// Internationalization files
$wgMessagesDirs['SocialProfile'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['SocialProfileAlias'] = __DIR__ . '/SocialProfile.alias.php';

$wgMessagesDirs['SocialProfileUserProfile'] = __DIR__ . '/UserProfile/i18n';

$wgExtensionMessagesFiles['SocialProfileNamespaces'] = __DIR__ . '/SocialProfile.namespaces.php';
$wgExtensionMessagesFiles['AvatarMagic'] = __DIR__ . '/UserProfile/includes/avatar/Avatar.i18n.magic.php';

// Hack to make installer load extension properly. (T243861)
// Based on Installer::includeExtensions()
if ( defined( 'MEDIAWIKI_INSTALL' ) ) {
	$subext = [
		__DIR__ . '/UserProfile/extension.json' => 1,
		__DIR__ . '/SystemGifts/extension.json' => 1,
		__DIR__ . '/UserActivity/extension.json' => 1,
		__DIR__ . '/UserBoard/extension.json' => 1,
		__DIR__ . '/UserRelationship/extension.json' => 1,
		__DIR__ . '/UserStats/extension.json' => 1,
		__DIR__ . '/UserGifts/extension.json' => 1,
	];

	$registry = new ExtensionRegistry();
	$data = $registry->readFromQueue( $subext );
	if ( method_exists( AutoLoader::class, 'registerClasses' ) ) {
		// MediaWiki 1.39+
		AutoLoader::registerClasses( $data['autoloaderClasses'] );
		if ( !isset( $wgAutoloadClasses ) ) {
			$wgAutoloadClasses = [];
		}
	} else {
		// @phan-suppress-next-line PhanUndeclaredVariableAssignOp
		$wgAutoloadClasses += $data['globals']['wgAutoloadClasses'];
	}
}

// Classes to be autoloaded
$wgAutoloadClasses['ContinuumUniverses\\ContinuumProfile\\SocialProfileFileBackend'] = __DIR__ . '/SocialProfileFileBackend.php';
$wgDefaultUserOptions['echo-subscriptions-web-social-rel'] = true;
$wgDefaultUserOptions['echo-subscriptions-email-social-rel'] = false;

// New special pages
$wgSpecialPages['EditProfile'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\SpecialEditProfile';
$wgSpecialPages['PopulateUserProfiles'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\SpecialPopulateUserProfiles';
$wgSpecialPages['RemoveAvatar'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\RemoveAvatar';
$wgSpecialPages['ToggleUserPage'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\SpecialToggleUserPage';
$wgSpecialPages['UpdateProfile'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\SpecialUpdateProfile';
$wgSpecialPages['UploadAvatar'] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\SpecialUploadAvatar';

// file backend to use defaults to FileSystem
// this allows you to use e.g. swift.
// to setup your own file backend see
// https://www.mediawiki.org/wiki/Manual:$wgFileBackends
$wgSocialProfileFileBackend = '';

// Extension credits that show up on Special:Version
$wgExtensionCredits['other'][] = [
	'path' => __FILE__,
	'name' => 'ContinuumProfile',
	'author' => [ 'Aaron Wright', 'David Pean', 'Jack Phoenix', 'Christian Daniel Jensen', 'Anita' ],
	'version' => '1.14',
	'url' => 'https://www.mediawiki.org/wiki/Extension:SocialProfile',
	'descriptionmsg' => 'socialprofile-desc',
];

// Hooked functions
$wgAutoloadClasses['ContinuumUniverses\\ContinuumProfile\\SocialProfileHooks'] = __DIR__ . '/SocialProfileHooks.php';

wfLoadExtensions( [
	'ContinuumProfile/UserProfile',
	'ContinuumProfile/SystemGifts', // SystemGifts (awards functionality)
	'ContinuumProfile/UserActivity', // UserActivity - recent social changes
	'ContinuumProfile/UserBoard',
	'ContinuumProfile/UserRelationship',
	'ContinuumProfile/UserStats',
	'ContinuumProfile/UserGifts',
] );

$wgHooks['BeforePageDisplay'][] = 'ContinuumUniverses\\ContinuumProfile\\SocialProfileHooks::onBeforePageDisplay';
$wgHooks['CanonicalNamespaces'][] = 'ContinuumUniverses\\ContinuumProfile\\SocialProfileHooks::onCanonicalNamespaces';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'ContinuumUniverses\\ContinuumProfile\\SocialProfileHooks::onLoadExtensionSchemaUpdates';
$wgHooks['ParserFirstCallInit'][] = 'ContinuumUniverses\\ContinuumProfile\\UserProfile\\AvatarParserFunction::setupAvatarParserFunction';

// ResourceLoader module definitions for certain components which do not have
// their own loader file

// General
$wgResourceModules['ext.socialprofile.clearfix'] = [
	'styles' => 'clearfix.css',
	'localBasePath' => __DIR__ . '/shared',
	'remoteExtPath' => 'ContinuumProfile/shared',
];

$wgResourceModules['ext.socialprofile.responsive'] = [
	'styles' => 'responsive.less',
	'localBasePath' => __DIR__ . '/shared',
	'remoteExtPath' => 'ContinuumProfile/shared',
];

// General/shared JS modules -- not (necessarily) directly used by SocialProfile,
// but rather by other social tools which depend on SP
// @see https://phabricator.wikimedia.org/T100025
$wgResourceModules['ext.socialprofile.LightBox'] = [
	'scripts' => 'LightBox.js',
	'localBasePath' => __DIR__ . '/shared',
	'remoteExtPath' => 'SocialProfile/shared',
];

// End ResourceLoader stuff

if ( !defined( 'NS_USER_WIKI' ) ) {
	define( 'NS_USER_WIKI', 200 );
}

if ( !defined( 'NS_USER_WIKI_TALK' ) ) {
	define( 'NS_USER_WIKI_TALK', 201 );
}

if ( !defined( 'NS_USER_PROFILE' ) ) {
	define( 'NS_USER_PROFILE', 202 );
}

if ( !defined( 'NS_USER_PROFILE_TALK' ) ) {
	define( 'NS_USER_PROFILE_TALK', 203 );
}
