<?php
if ( !class_exists('Puc_v4p4_Vcs_BitBucketServerApi', false) ):

	/**
	 * for self hosted bitbucket servers
	 * Using the V1.0 API (https://docs.atlassian.com/bitbucket-server/rest/5.10.1/bitbucket-rest.html?utm_source=%2Fstatic%2Frest%2Fbitbucket-server%2Flatest%2Fbitbucket-rest.html&utm_medium=301)
	 * Using an Auth token for Authentification
	 *
	 */
	class Puc_v4p4_Vcs_BitBucketServerApi extends Puc_v4p4_Vcs_Api {

		protected $tagNameProperty = 'displayId';

		/**
		 * @var string
		 */
		private $bitbucketServerRoot;

		/**
		 * @var string
		 */
		private $projectKey;

		/**
		 * @var string
		 */
		private $repository;
		
		/**
		 * @var string Authentication Token for API access. Optional.
		 */
		private $accessToken;

		/**
		 * Extract projectKey and repository name from the URL
		 *
		 * @param $repositoryUrl /bitbucket/projects/INT/repos/wordpress-bv-info-service/
		 * @param $accessToken
		 *
		 */
		public function __construct($repositoryUrl, $accessToken = null) {

			// TODO: extract from repositoryUrl
			$this->bitbucketServerRoot = 'https://int.bold-ventures.de/bitbucket';

			$path = @parse_url($repositoryUrl, PHP_URL_PATH);
			if ( preg_match('@^/projects/?(?P<projectKey>[^/]+?)/repos/(?P<repository>[^/#?&]+?)/?$@', $path, $matches) ) {
				$this->projectKey = $matches['projectKey'];
				$this->repository = $matches['repository'];
			} else {
				throw new InvalidArgumentException('Invalid BitBucket repository URL: "' . $repositoryUrl . '"');
			}

			parent::__construct($repositoryUrl, $accessToken);
		}

		/**
		 * Figure out which reference (i.e tag or branch) contains the latest version.
		 *
		 * @param string $configBranch Start looking in this branch.
		 * @return null|Puc_v4p4_Vcs_Reference
		 */
		public function chooseReference($configBranch) {

			$updateSource = null;

			// Check if there's a "Stable tag: 1.2.3" header that points to a valid tag.
			$updateSource = $this->getStableTag($configBranch);

			// Look for version-like tags.
			if ( !$updateSource && ($configBranch === 'master') ) {
				$updateSource = $this->getLatestTag();
			}

			// If all else fails, use the specified branch itself.
			if ( !$updateSource ) {
				$updateSource = $this->getBranch($configBranch);
			}

			return $updateSource;
		}
		
		public function getBranch($branchName) {
			$branch = $this->api('/branches/' . $branchName);
			if ( is_wp_error($branch) || empty($branch) ) {
				return null;
			}
			return new Puc_v4p4_Vcs_Reference(array(
				'name' => $branch->name,
				'updated' => $branch->target->date,
				'downloadUrl' => $this->getDownloadUrl($branch->id),
			));
		}
		/**
		 * Get a specific tag.
		 *
		 * @param string $tagName
		 * @return Puc_v4p4_Vcs_Reference|null
		 */
		public function getTag($tagName) {

			$tag = $this->api('/tags/' . $tagName, '1.0');
			if ( is_wp_error($tag) || empty($tag) ) {
				return null;
			}
			
			/* Example /tag response for API v1.0
				{
				"id": "refs/tags/v1.2.2",
				"displayId": "v1.2.2",
				"type": "TAG",
				"latestCommit": "6e4b261e4ec0d783bf146c802212569e47931643",
				"latestChangeset": "6e4b261e4ec0d783bf146c802212569e47931643",
				"hash": null
				}			
			*/

			$commit = $this->api('/commits/' . $tag->latestCommit);
			if ( is_wp_error($commit) || empty($commit) ) {
				return null;
			}			
			
			return new Puc_v4p4_Vcs_Reference(array(
				'name' => $tag->displayId,
				'version' => ltrim($tag->displayId, 'v'),
				'updated' => $commit->committerTimestamp, // TODO: is datetime formating needed?
				'downloadUrl' => $this->getDownloadUrl($tag->id)
			));
		}
		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Puc_v4p4_Vcs_Reference|null
		 */
		public function getLatestTag() {

			$tags = $this->api('/tags?sort=-target.date');

			if ( !isset($tags, $tags->values) || !is_array($tags->values) ) {
				return null;
			}

			// Filter and sort the list of tags.
			$versionTags = $this->sortTagsByVersion($tags->values);

			// Return the first result.
			if ( !empty($versionTags) ) {
				$tag = $versionTags[0];

				$commit = $this->api('/commits/' . $tag->latestCommit);
				if ( is_wp_error($commit) || empty($commit) ) {
					return null;
				}

				return new Puc_v4p4_Vcs_Reference(array(
					'name' => $tag->name,
					'version' => ltrim($tag->name, 'v'),
					'updated' => $commit->committerTimestamp,
					'downloadUrl' => $this->getDownloadUrl($tag->id),
				));
			}
			return null;
		}
		/**
		 * Get the tag/ref specified by the "Stable tag" header in the readme.txt of a given branch.
		 *
		 * @param string $branch
		 * @return null|Puc_v4p4_Vcs_Reference
		 */
		protected function getStableTag($branch) {
			$remoteReadme = $this->getRemoteReadme($branch);
			if ( !empty($remoteReadme['stable_tag']) ) {
				$tag = $remoteReadme['stable_tag'];
				//You can explicitly opt out of using tags by setting "Stable tag" to
				//"trunk" or the name of the current branch.
				if ( ($tag === $branch) || ($tag === 'trunk') ) {
					return $this->getBranch($branch);
				}
				return $this->getTag($tag);
			}
			return null;
		}

		/**
		 *
		 * @param string $ref
		 * @param string $version
		 * @return string
		 */
		protected function getDownloadUrl($ref, $version = '1.0') {
			return implode('/', array(
				$this->bitbucketServerRoot,
				'rest',
				'api',
				$version,
				'projects',
				$this->projectKey,
				'repos',
				$this->repository,
				ltrim('archive?at=' . $ref . '&format=zip', '/')
			));

		}
		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function getRemoteFile($path, $ref = 'master') {
			$response = $this->api('src/' . $ref . '/' . ltrim($path), '1.0');
			if ( is_wp_error($response) || !isset($response, $response->data) ) {
				return null;
			}
			return $response->data;
		}
		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function getLatestCommitTime($ref) {
			$response = $this->api('commits/' . $ref);
			if ( isset($response->values, $response->values[0], $response->values[0]->date) ) {
				return $response->values[0]->date;
			}
			return null;
		}
		/**
		 * Perform a BitBucket API 2.0 request.
		 *
		 * @param string $url
		 * @param string $version
		 * @return mixed|WP_Error
		 */
		public function api($url, $version = '1.0') {
			// /bitbucket/rest/api/latest/projects/INT/repos/wordpress-bv-info-service
			$url = implode('/', array(
				$this->bitbucketServerRoot,
				'rest',
				'api',
				$version,
				'projects',
				$this->projectKey,
				'repos',
				$this->repository,
				ltrim($url, '/')
			));
			$baseUrl = $url;
			if ( $this->oauth ) {
				$url = $this->oauth->sign($url,'GET');
			}
			$options = array('timeout' => 10, 'Authorization' => 'Bearer '.$this->accessToken);
			if ( !empty($this->httpFilterName) ) {
				$options = apply_filters($this->httpFilterName, $options);
			}
			$response = wp_remote_get($url, $options);
			if ( is_wp_error($response) ) {
				do_action('puc_api_error', $response, null, $url, $this->slug);
				return $response;
			}
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			if ( $code === 200 ) {
				$document = json_decode($body);
				return $document;
			}
			$error = new WP_Error(
				'puc-bitbucket-http-error',
				sprintf('BitBucket API error. Base URL: "%s",  HTTP status code: %d.', $baseUrl, $code)
			);
			do_action('puc_api_error', $error, $response, $url, $this->slug);
			return $error;
		}
		/**
		 * @param array $credentialsh
		 */
		public function setAuthentication($credentials) {
			parent::setAuthentication($credentials);
			if ( !empty($credentials) && !empty($credentials['consumer_key']) ) {
				$this->oauth = new Puc_v4p4_OAuthSignature(
					$credentials['consumer_key'],
					$credentials['consumer_secret']
				);
			} else {
				$this->oauth = null;
			}
		}
		public function signDownloadUrl($url) {
			//Add authentication data to download URLs. Since OAuth signatures incorporate
			//timestamps, we have to do this immediately before inserting the update. Otherwise
			//authentication could fail due to a stale timestamp.
			if ( $this->oauth ) {
				$url = $this->oauth->sign($url);
			}
			return $url;
		}
	}
endif;
	
	
?>
