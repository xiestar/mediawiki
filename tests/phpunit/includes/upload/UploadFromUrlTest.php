<?php

/**
 * @group Broken
 * @group Upload
 * @group Database
 *
 * @covers UploadFromUrl
 */
class UploadFromUrlTest extends ApiTestCase {
	protected function setUp() {
		parent::setUp();

		$this->setMwGlobals( array(
			'wgEnableUploads' => true,
			'wgAllowCopyUploads' => true,
			'wgAllowAsyncCopyUploads' => true,
		) );

		if ( wfLocalFile( 'UploadFromUrlTest.png' )->exists() ) {
			$this->deleteFile( 'UploadFromUrlTest.png' );
		}
	}

	protected function doApiRequest( array $params, array $unused = null,
		$appendModule = false, User $user = null
	) {
		global $wgRequest;

		$req = new FauxRequest( $params, true, $wgRequest->getSession() );
		$module = new ApiMain( $req, true );
		$module->execute();

		return array(
			$module->getResult()->getResultData( null, array( 'Strip' => 'all' ) ),
			$req
		);
	}

	/**
	 * Ensure that the job queue is empty before continuing
	 */
	public function testClearQueue() {
		$job = JobQueueGroup::singleton()->pop();
		while ( $job ) {
			$job = JobQueueGroup::singleton()->pop();
		}
		$this->assertFalse( $job );
	}

	/**
	 * @depends testClearQueue
	 */
	public function testSetupUrlDownload( $data ) {
		$token = $this->user->getEditToken();
		$exception = false;

		try {
			$this->doApiRequest( array(
				'action' => 'upload',
			) );
		} catch ( UsageException $e ) {
			$exception = true;
			$this->assertEquals( "The token parameter must be set", $e->getMessage() );
		}
		$this->assertTrue( $exception, "Got exception" );

		$exception = false;
		try {
			$this->doApiRequest( array(
				'action' => 'upload',
				'token' => $token,
			), $data );
		} catch ( UsageException $e ) {
			$exception = true;
			$this->assertEquals( "One of the parameters sessionkey, file, url, statuskey is required",
				$e->getMessage() );
		}
		$this->assertTrue( $exception, "Got exception" );

		$exception = false;
		try {
			$this->doApiRequest( array(
				'action' => 'upload',
				'url' => 'http://www.example.com/test.png',
				'token' => $token,
			), $data );
		} catch ( UsageException $e ) {
			$exception = true;
			$this->assertEquals( "The filename parameter must be set", $e->getMessage() );
		}
		$this->assertTrue( $exception, "Got exception" );

		$this->user->removeGroup( 'sysop' );
		$exception = false;
		try {
			$this->doApiRequest( array(
				'action' => 'upload',
				'url' => 'http://www.example.com/test.png',
				'filename' => 'UploadFromUrlTest.png',
				'token' => $token,
			), $data );
		} catch ( UsageException $e ) {
			$exception = true;
			$this->assertEquals( "Permission denied", $e->getMessage() );
		}
		$this->assertTrue( $exception, "Got exception" );

		$this->user->addGroup( 'sysop' );
		$data = $this->doApiRequest( array(
			'action' => 'upload',
			'url' => 'http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png',
			'asyncdownload' => 1,
			'filename' => 'UploadFromUrlTest.png',
			'token' => $token,
		), $data );

		$this->assertEquals( $data[0]['upload']['result'], 'Queued', 'Queued upload' );

		$job = JobQueueGroup::singleton()->pop();
		$this->assertThat( $job, $this->isInstanceOf( 'UploadFromUrlJob' ), 'Queued upload inserted' );
	}

	/**
	 * @depends testClearQueue
	 */
	public function testAsyncUpload( $data ) {
		$token = $this->user->getEditToken();

		$this->user->addGroup( 'users' );

		$data = $this->doAsyncUpload( $token, true );
		$this->assertEquals( $data[0]['upload']['result'], 'Success' );
		$this->assertEquals( $data[0]['upload']['filename'], 'UploadFromUrlTest.png' );
		$this->assertTrue( wfLocalFile( $data[0]['upload']['filename'] )->exists() );

		$this->deleteFile( 'UploadFromUrlTest.png' );

		return $data;
	}

	/**
	 * @depends testClearQueue
	 */
	public function testAsyncUploadWarning( $data ) {
		$token = $this->user->getEditToken();

		$this->user->addGroup( 'users' );

		$data = $this->doAsyncUpload( $token );

		$this->assertEquals( $data[0]['upload']['result'], 'Warning' );
		$this->assertTrue( isset( $data[0]['upload']['sessionkey'] ) );

		$data = $this->doApiRequest( array(
			'action' => 'upload',
			'sessionkey' => $data[0]['upload']['sessionkey'],
			'filename' => 'UploadFromUrlTest.png',
			'ignorewarnings' => 1,
			'token' => $token,
		) );
		$this->assertEquals( $data[0]['upload']['result'], 'Success' );
		$this->assertEquals( $data[0]['upload']['filename'], 'UploadFromUrlTest.png' );
		$this->assertTrue( wfLocalFile( $data[0]['upload']['filename'] )->exists() );

		$this->deleteFile( 'UploadFromUrlTest.png' );

		return $data;
	}

	/**
	 * @depends testClearQueue
	 */
	public function testSyncDownload( $data ) {
		$token = $this->user->getEditToken();

		$job = JobQueueGroup::singleton()->pop();
		$this->assertFalse( $job, 'Starting with an empty jobqueue' );

		$this->user->addGroup( 'users' );
		$data = $this->doApiRequest( array(
			'action' => 'upload',
			'filename' => 'UploadFromUrlTest.png',
			'url' => 'http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png',
			'ignorewarnings' => true,
			'token' => $token,
		), $data );

		$job = JobQueueGroup::singleton()->pop();
		$this->assertFalse( $job );

		$this->assertEquals( 'Success', $data[0]['upload']['result'] );
		$this->deleteFile( 'UploadFromUrlTest.png' );

		return $data;
	}

	public function testLeaveMessage() {
		$token = $this->user->user->getEditToken();

		$talk = $this->user->user->getTalkPage();
		if ( $talk->exists() ) {
			$page = WikiPage::factory( $talk );
			$page->doDeleteArticle( '' );
		}

		$this->assertFalse(
			(bool)$talk->getArticleID( Title::GAID_FOR_UPDATE ),
			'User talk does not exist'
		);

		$this->doApiRequest( array(
			'action' => 'upload',
			'filename' => 'UploadFromUrlTest.png',
			'url' => 'http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png',
			'asyncdownload' => 1,
			'token' => $token,
			'leavemessage' => 1,
			'ignorewarnings' => 1,
		) );

		$job = JobQueueGroup::singleton()->pop();
		$this->assertEquals( 'UploadFromUrlJob', get_class( $job ) );
		$job->run();

		$this->assertTrue( wfLocalFile( 'UploadFromUrlTest.png' )->exists() );
		$this->assertTrue( (bool)$talk->getArticleID( Title::GAID_FOR_UPDATE ), 'User talk exists' );

		$this->deleteFile( 'UploadFromUrlTest.png' );

		$exception = false;
		try {
			$this->doApiRequest( array(
				'action' => 'upload',
				'filename' => 'UploadFromUrlTest.png',
				'url' => 'http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png',
				'asyncdownload' => 1,
				'token' => $token,
				'leavemessage' => 1,
			) );
		} catch ( UsageException $e ) {
			$exception = true;
			$this->assertEquals(
				'Using leavemessage without ignorewarnings is not supported',
				$e->getMessage()
			);
		}
		$this->assertTrue( $exception );

		$job = JobQueueGroup::singleton()->pop();
		$this->assertFalse( $job );

		return;
		/*
		// Broken until using leavemessage with ignorewarnings is supported
		$talkRev = Revision::newFromTitle( $talk );
		$talkSize = $talkRev->getSize();

		$job->run();

		$this->assertFalse( wfLocalFile( 'UploadFromUrlTest.png' )->exists() );

		$talkRev = Revision::newFromTitle( $talk );
		$this->assertTrue( $talkRev->getSize() > $talkSize, 'New message left' );
		*/
	}

	/**
	 * Helper function to perform an async upload, execute the job and fetch
	 * the status
	 *
	 * @param string $token
	 * @param bool $ignoreWarnings
	 * @param bool $leaveMessage
	 * @return array The result of action=upload&statuskey=key
	 */
	private function doAsyncUpload( $token, $ignoreWarnings = false, $leaveMessage = false ) {
		$params = array(
			'action' => 'upload',
			'filename' => 'UploadFromUrlTest.png',
			'url' => 'http://upload.wikimedia.org/wikipedia/mediawiki/b/bc/Wiki.png',
			'asyncdownload' => 1,
			'token' => $token,
		);
		if ( $ignoreWarnings ) {
			$params['ignorewarnings'] = 1;
		}
		if ( $leaveMessage ) {
			$params['leavemessage'] = 1;
		}

		$data = $this->doApiRequest( $params );
		$this->assertEquals( $data[0]['upload']['result'], 'Queued' );
		$this->assertTrue( isset( $data[0]['upload']['statuskey'] ) );
		$statusKey = $data[0]['upload']['statuskey'];

		$job = JobQueueGroup::singleton()->pop();
		$this->assertEquals( 'UploadFromUrlJob', get_class( $job ) );

		$status = $job->run();
		$this->assertTrue( $status );

		$data = $this->doApiRequest( array(
			'action' => 'upload',
			'statuskey' => $statusKey,
			'token' => $token,
		) );

		return $data;
	}

	protected function deleteFile( $name ) {
		$t = Title::newFromText( $name, NS_FILE );
		$this->assertTrue( $t->exists(), "File '$name' exists" );

		if ( $t->exists() ) {
			$file = wfFindFile( $name, array( 'ignoreRedirect' => true ) );
			$empty = "";
			FileDeleteForm::doDelete( $t, $file, $empty, "none", true );
			$page = WikiPage::factory( $t );
			$page->doDeleteArticle( "testing" );
		}
		$t = Title::newFromText( $name, NS_FILE );

		$this->assertFalse( $t->exists(), "File '$name' was deleted" );
	}
}
