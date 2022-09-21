<?php

/**
 * Minimal set of classes necessary to fulfill needs of parts of WikimediaEvents relying on
 * the EventBus extension.
 * phpcs:disable MediaWiki.Files.ClassMatchesFilename,Generic.Files.OneObjectStructurePerFile,MediaWiki.Commenting.FunctionComment
 */

namespace MediaWiki\Extension\EventBus {

	use MediaWiki\User\UserIdentity;

	class EventFactory {
		public function createMediaWikiCommonAttrs( UserIdentity $user ): array {
		}
	}

}
