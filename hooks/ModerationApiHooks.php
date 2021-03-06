<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
	@file
	@brief Hooks related to edits/uploads via API.
*/

class ModerationApiHooks {

	/**
		onApiCheckCanExecute()
		Prevent uploads via API for non-automoderated user

		FIXME: this is needed because UploadVerifyFile hook doesn't receive description.
		Find a way to workaround this.
		(MediaWiki 1.28+ has UploadVerifyUpload hook, but we still need to support 1.23).
	*/
	public static function onApiCheckCanExecute( $module, $user, &$message ) {
		if ( $module == 'upload' && !ModerationCanSkip::canSkip( $user ) ) {
			$message = 'nouploadmodule';
			return false;
		}

		return true;
	}

	/*
		onApiBeforeMain()
		Make sure that
		1) api.php?action=edit&appendtext=... will append to the pending version.
		2) api.php?action=edit&section=N won't complain 'nosuchsection' if
		section N exists in the pending version.
	*/
	public static function onApiBeforeMain( &$main ) {
		$request = $main->getRequest();
		if ( $request->getVal( 'action' ) != 'edit' ) {
			return true; /* Nothing to do */
		}

		$section = $request->getVal( 'section', '' );
		$prepend = $request->getVal( 'prependtext', '' );
		$append = $request->getVal( 'appendtext', '' );

		if ( !$prepend && !$append && !$section ) {
			return true; /* Usual api.php?action=edit&text= works correctly with Moderation */
		}

		$pageObj = $main->getTitleOrPageId( $request->getValues( 'title', 'pageid' ) );
		$title = $pageObj->getTitle();

		$row = ModerationPreload::singleton()->loadUnmoderatedEdit( $title );
		if ( !$row ) {
			return true; /* No pending version - ApiEdit will handle this correctly */
		}

		$oldContent = ContentHandler::makeContent( $row->text, $title );
		$content = $oldContent;
		if ( $section ) {
			if ( $section == 'new' ) {
				$content = ContentHandler::makeContent( '', $title );
			}
			else {
				$content = $oldContent->getSection( $section );
				if ( !$content ) {
					$this->dieUsage( "There is no section {$section}.", 'nosuchsection' );
				}
			}
		}

		$text = $content->getNativeData();

		/* Now we remove appendtext/prependtext from WebRequest object
			and make ApiEdit think that this is a usual action=edit&text=... call.

			Otherwise ApiEdit will attempt to prepend/append to the last revision
			of the page, not to the preloaded revision.
		*/
		$query = $request->getValues();
		if ( !isset( $query['text'] ) ) {
			$query['text'] = $prepend . $text . $append;
		}
		unset( $query['prependtext'] );
		unset( $query['appendtext'] );

		$query['text'] = rtrim( $query['text'] );

		if ( $section ) {
			/* We also remove section=N parameter,
				because if section N doesn't exist in the page,
				ApiEditPage will incorrectly complain "nosuchsection"
				(even when section N exists in the pending version).
			*/
			$newSectionContent = ContentHandler::makeContent( $query['text'], $title );
			$newContent = $oldContent->replaceSection( $section, $newSectionContent );

			$query['text'] = $newContent->getNativeData();
			unset( $query['section'] );
		}

		$req = new DerivativeRequest( $request, $query, true );
		$main->getContext()->setRequest( $req );

		/* Let ApiEdit handle the rest */
		return true;
	}
}
