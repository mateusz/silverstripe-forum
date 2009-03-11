<?php


/**
 * Forum member profile controller
 */
class ForumMemberProfile extends Page_Controller {

	/**
	 * Constructor
	 */
	function __construct() {
		return parent::__construct(null);
	}


	/**
	 * Initialise the controller
	 */
	function init() {
		Requirements::themedCSS('Forum');

		Requirements::javascript("jsparty/prototype.js");
 		Requirements::javascript("jsparty/behaviour.js");
		if($this->OpenIDAvailable())
			Requirements::javascript("forum/javascript/Forum_openid_description.js");

		parent::init();
 	}


	/**
	 * Get the URL for the login action
	 *
	 * @return string URL to the login action
	 */
	function LoginURL() {
		return $this->Link("login");
	}


	/**
	 * Is OpenID support available?
	 *
	 * This method checks if the {@link OpenIDAuthenticator} is available and
	 * registered.
	 *
	 * @return bool Returns TRUE if OpenID is available, FALSE otherwise.
	 */
	function OpenIDAvailable() {
		if(class_exists('Authenticator') == false)
			return false;

		return Authenticator::is_registered("OpenIDAuthenticator");
	}


	/**
	 * The login action
	 *
	 * It simple sets the return URL and forwards to the standard login form.
	 */
	function login() {
		Session::set('Security.Message.message',
								 'Please enter your credentials to access the forum.');
		Session::set('Security.Message.type', 'status');
		Session::set("BackURL", $this->Link());
		Director::redirect('Security/login');
	}


 	/**
 	 * Get the latest 10 posts by this member
 	 */
 	function LatestPosts() {
 		$memberID = $this->urlParams['ID'];
 		$SQL_memberID = Convert::raw2sql($memberID);

 		if(!is_numeric($SQL_memberID)) return null;

 		$posts = DataObject::get("Post", "`AuthorID` = '$SQL_memberID'",
														 "`Created` DESC", "", "0,10");

 		return $posts;
 	}


	/**
	 * Show the registration form
	 */
	function register() {
		return array(
			"Title" => "SilverStripe Forum",
			"Subtitle" => "Register",
			"Abstract" => DataObject::get_one("ForumHolder")->ProfileAbstract,
			"Form" => $this->RegistrationForm(),
		);
	}


	/**
	 * Factory method for the registration form
	 *
	 * @return Form Returns the registration form
	 */
	function RegistrationForm() {
		$data = Session::get("FormInfo.Form_RegistrationForm.data");

		$use_openid =
			($this->OpenIDAvailable() == true) &&
			(isset($data['IdentityURL']) && !empty($data['IdentityURL'])) ||
			(isset($_POST['IdentityURL']) && !empty($_POST['IdentityURL']));

		$fields = singleton('Member')->getForumFields(true, $use_openid);
		$form = new Form($this, 'RegistrationForm', $fields,
			new FieldSet(new FormAction("doregister", "Register")),
			($use_openid)
				? new RequiredFields("Nickname", "Email")
				: new RequiredFields("Nickname", "Email", "Password",
														 "ConfirmPassword")
		);

		$member = new Member();
		$form->loadDataFrom($member);


		// If OpenID is used, we should also load the data stored in the session
		if($use_openid && is_array($data)) {
			$form->loadDataFrom($data);
		}

		return $form;
	}


	/**
	 * Register a new member
	 *
	 * @param array $data User submitted data
	 * @param Form $form The used form
	 */
	function doregister($data, $form) {
		if($member = DataObject::get_one("Member",
				"`Email` = '". Convert::raw2sql($data['Email']) . "'")) {
  		if($member) {
  			$form->addErrorMessage("Blurb",
					"Sorry, that email address already exists. Please choose another.",
					"bad");

  			// Load errors into session and post back
				Session::set("FormInfo.Form_RegistrationForm.data", $data);
  			Director::redirectBack();
  			return;
  		}
  	} elseif($this->OpenIDAvailable() &&
				($member = DataObject::get_one("Member",
					"`IdentityURL` = '". Convert::raw2sql($data['IdentityURL']) .
					"'"))) {
  		if($member) {
  			$form->addErrorMessage("Blurb",
					"Sorry, that OpenID is already registered. Please choose " .
						"another or register without OpenID.",
					"bad");

				// Load errors into session and post back
				Session::set("FormInfo.Form_RegistrationForm.data", $data);
				Director::redirectBack();
  			return;
			}
  	} elseif($member = DataObject::get_one("Member",
				"`Nickname` = '". Convert::raw2sql($data['Nickname']) . "'")) {
  		if($member) {
  			$form->addErrorMessage("Blurb",
					"Sorry, that nickname already exists. Please choose another.",
					"bad");

				// Load errors into session and post back
				Session::set("FormInfo.Form_RegistrationForm.data", $data);
				Director::redirectBack();
				return;
			}
		}

  	// create the new member
		$member = Object::create('Member');
		$form->saveInto($member);

		// check password fields are the same before saving
		if($data['Password'] == $data['ConfirmPassword']) {
			$member->Password = $data['Password'];
		} else {
			$form->addErrorMessage("Password",
				"Both passwords need to match. Please try again.",
				"bad");

			// Load errors into session and post back
			Session::set("FormInfo.Form_RegistrationForm.data", $data);
			Director::redirectBack();
		}

		$member->write();
		$member->login();
		Group::addToGroupByName($member, 'forum-members');

		return array("Form" => DataObject::get_one("ForumHolder")->ProfileAdd);
	}


	/**
	 * Start registration with OpenID
	 *
	 * @param array $data Data passed by the director
	 * @param array $message Message and message type to output
	 * @return array Returns the needed data to render the registration form.
	 */
	function registerwithopenid($data, $message = null) {
		return array(
			"Title" => "SilverStripe Forum",
			"Subtitle" => "Register with OpenID",
			"Abstract" => ($message)
				? '<p class="' . $message['type'] . '">' .
						Convert::raw2xml($message['message']) . '</p>'
				: "<p>Please enter your OpenID to continue the registration</p>",
			"Form" => $this->RegistrationWithOpenIDForm(),
		);
	}


	/**
	 * Factory method for the OpenID registration form
	 *
	 * @return Form Returns the OpenID registration form
	 */
	function RegistrationWithOpenIDForm() {
		$form = new Form($this, 'RegistrationWithOpenIDForm',
      new FieldSet(new TextField("OpenIDURL", "OpenID URL", "", null)),
			new FieldSet(new FormAction("doregisterwithopenid", "Register")),
			new RequiredFields("OpenIDURL")
		);

		return $form;
	}


	/**
	 * Register a new member
	 */
	function doregisterwithopenid($data, $form) {
		$openid = trim($data['OpenIDURL']);
		Session::set("FormInfo.Form_RegistrationWithOpenIDForm.data", $data);

    if(strlen($openid) == 0) {
			if(!is_null($form)) {
  			$form->addErrorMessage("Blurb",
					"Please enter your OpenID or your i-name.",
					"bad");
			}
			Director::redirectBack();
			return;
		}


		$trust_root = Director::absoluteBaseURL();
		$return_to_url = $trust_root . $this->Link('ProcessOpenIDResponse');

		$consumer = new Auth_OpenID_Consumer(new OpenIDStorage(),
																				 new SessionWrapper());


		// No auth request means we can't begin OpenID
		$auth_request = $consumer->begin($openid);
		if(!$auth_request) {
			if(!is_null($form)) {
  			$form->addErrorMessage("Blurb",
					"That doesn't seem to be a valid OpenID or i-name identifier. " .
					"Please try again.",
					"bad");
			}
			Director::redirectBack();
			return;
		}

		$SQL_identity = Convert::raw2sql($auth_request->endpoint->claimed_id);
		if($member = DataObject::get_one("Member",
				"Member.IdentityURL = '$SQL_identity'")) {
			if(!is_null($form)) {
  			$form->addErrorMessage("Blurb",
					"That OpenID or i-name is already registered. Use another one.",
					"bad");
			}
			Director::redirectBack();
			return;
		}


		// Add the fields for which we wish to get the profile data
    $sreg_request = Auth_OpenID_SRegRequest::build(null,
      array('nickname', 'fullname', 'email', 'country'));

    if($sreg_request) {
			$auth_request->addExtension($sreg_request);
    }


		if($auth_request->shouldSendRedirect()) {
			// For OpenID 1, send a redirect.
			$redirect_url = $auth_request->redirectURL($trust_root,
																								 $return_to_url);

			if(Auth_OpenID::isFailure($redirect_url)) {
				displayError("Could not redirect to server: " .
										 $redirect_url->message);
			} else {
				Director::redirect($redirect_url);
			}

		} else {
			// For OpenID 2, use a javascript form to send a POST request to the
			// server.
			$form_id = 'openid_message';
			$form_html = $auth_request->formMarkup($trust_root, $return_to_url,
																						 false,
																						 array('id' => $form_id));

			if(Auth_OpenID::isFailure($form_html)) {
				displayError("Could not redirect to server: " .
										 $form_html->message);
			} else {
				$page_contents = array(
					 "<html><head><title>",
					 "OpenID transaction in progress",
					 "</title></head>",
					 "<body onload='document.getElementById(\"". $form_id .
					   "\").submit()'>",
					 $form_html,
					 "<p>Click &quot;Continue&quot; to login. You are only seeing " .
					 "this because you appear to have JavaScript disabled.</p>",
					 "</body></html>");

				print implode("\n", $page_contents);
			}
		}
	}



	/**
	 * Function to process the response of the OpenID server
	 */
	function ProcessOpenIDResponse() {
		$consumer = new Auth_OpenID_Consumer(new OpenIDStorage(),
																				 new SessionWrapper());

		// Complete the authentication process using the server's response.
		$response = $consumer->complete();

		if($response->status == Auth_OpenID_SUCCESS) {
			Session::clear("FormInfo.Form_RegistrationWithOpenIDForm.data");
			$openid = $response->identity_url;

			if($response->endpoint->canonicalID) {
				$openid = $response->endpoint->canonicalID;
			}

			$sreg_resp = Auth_OpenID_SRegResponse::fromSuccessResponse($response);
			$sreg = $sreg_resp->contents();

			// Convert the simple registration data to the needed format
			// try to split fullname to get firstname and surname
			$data = array('IdentityURL' => $openid);
			if(isset($sreg['nickname']))
				$data['Nickname'] = $sreg['nickname'];
			if(isset($sreg['fullname'])) {
				$fullname = explode(' ', $sreg['fullname'], 2);
				if(count($fullname) == 2) {
					$data['FirstName'] = $fullname[0];
					$data['Surname'] = $fullname[1];
				} else {
					$data['Surname'] = $fullname[0];
				}
			}
			if(isset($sreg['country']))
				$data['Country'] = $sreg['country'];
			if(isset($sreg['email']))
				$data['Email'] = $sreg['email'];

			Session::set("FormInfo.Form_RegistrationForm.data", $data);
			Director::redirect($this->Link('register'));
			return;
		}


		// The server returned an error message, handle it!
		if($response->status == Auth_OpenID_CANCEL) {
			$error_message = 'The verification was cancelled. Please try again.';
		} else if($response->status == Auth_OpenID_FAILURE) {
			$error_message = 'The OpenID/i-name authentication failed.';
		} else {
			$error_message = 'An unexpected error occured. Please try again or ' .
				'register without OpenID';
		}

		$this->RegistrationWithOpenIDForm()->addErrorMessage("Blurb",
			$error_message, 'bad');

		Director::redirect($this->Link('registerwithopenid'));

	}


	/**
	 * Edit profile
	 *
	 * @return array Returns an array to render the edit profile page.
	 */
	function edit() {
		$form = $this->EditProfileForm()
			? $this->EditProfileForm()
			: "<p class=\"error message\">You don't have the permission to " .
				"edit that member.</p>";

		return array(
			"Title" => "Forum",
			"Subtitle" => DataObject::get_one("ForumHolder")->ProfileSubtitle,
			"Abstract" => DataObject::get_one("ForumHolder")->ProfileAbstract,
			"Form" => $form,
		);
	}


	/**
	 * Factory method for the edit profile form
	 *
	 * @return Form Returns the edit profile form.
	 */
	function EditProfileForm() {
		$member = $this->Member();
		$show_openid = (isset($member->IdentityURL) &&
										!empty($member->IdentityURL));

		$fields = singleton('Member')->getForumFields(false, $show_openid);
		$fields->push(new HiddenField("ID"));

		$form = new Form($this, 'EditProfileForm', $fields,
			new FieldSet(new FormAction("dosave", "Save changes")),
			new RequiredFields("Nickname")
		);

		if($member && $member->hasMethod('canEdit') && $member->canEdit()) {
			$member->Password = '';
			$form->loadDataFrom($member);
			return $form;
		} else {
			return null;
		}
	}


	/**
	 * Save member profile action
	 *
	 * @param array $data
	 * @param $form
	 */
	function dosave($data, $form) {
		$member = DataObject::get_by_id("Member", $data['ID']);

		if($member->canEdit()) {
			if(!empty($data['Password']) && !empty($data['ConfirmPassword'])) {
				if($data['Password'] == $data['ConfirmPassword']) {
					$member->Password = $data['Password'];
				} else {
					$form->addErrorMessage("Blurb",
						"Both passwords need to match. Please try again.",
						"bad");
					Director::redirectBack();
				}
			} else {
				$form->dataFieldByName("Password")->setValue($member->Password);
			}
		}


		$nicknameCheck = DataObject::get_one("Member",
			"`Nickname` = '{$data['Nickname']}' AND `Member`.`ID` != '{$member->ID}'");

		if($nicknameCheck) {
			$form->addErrorMessage("Blurb",
				"Sorry, that nickname already exists. Please choose another.",
				"bad");
			Director::redirectBack();
			return;
		}

		$form->saveInto($member);
		$member->write();
		Director::redirect('thanks');
	}


	/**
	 * Print the "thank you" page
	 *
	 * Used after saving changes to a member profile.
	 *
	 * @return array Returns the needed data to render the page.
	 */
	function thanks() {
		return array(
			"Form" => DataObject::get_one("ForumHolder")->ProfileModify
		);
	}


	/**
	 * Create a link
	 *
	 * @param string $action Name of the action to link to
	 * @return string Returns the link to the passed action.
	 */
 	function Link($action = null) {
 		return "$this->class/$action";
 	}


	/**
	 * Return the with the passed ID (via URL parameters) or the current user
	 *
	 * @return bool|Member Returns the member object or FALSE if the member
	 *                     was not found
	 */
 	function Member() {
		if(is_numeric($this->urlParams['ID'])) {
			$member = DataObject::get_by_id("Member", $this->urlParams['ID']);
			if($this->urlParams['Action'] == "show") {
				$member->Country = Geoip::countryCode2name(
					$member->Country ? $member->Country : "NZ");
			}
			return $member;
		} else {
			$member = Member::currentUser();
			if($this->urlParams['Action'] == "show") {
				$member->Country = Geoip::countryCode2name(
					$member->Country ? $member->Country : "NZ");
			}
			return $member;
		}
	}


	/**
	 * Get the latest member in the system
	 *
	 * @return Member Returns the latest member in the system.
	 */
	function LatestMember($limit = null) {
		return DataObject::get("Member", "", "`Member`.`ID` DESC", "", 1);
	}


	/**
	 * This will trick SilverStripe into placing this page within the site
	 * tree
	 */
	function getParent() {
		$siblingForum = Forum_Controller::getLastForumAccessed();
		return $siblingForum->Parent;
	}


	/**
	 * This will trick SilverStripe into placing this page within the site
	 * tree
	 */
	function getParentID() {
		$siblingForum = Forum_Controller::getLastForumAccessed();
		return $siblingForum->ParentID;
	}


	/**
	 * Just return a pointer to $this
	 */
	function data() {
		return $this;
	}


	/**
	 * Get a list of currently online users (last 15 minutes)
	 */
	function CurrentlyOnline() {
		return DataObject::get("Member",
			"LastVisited > NOW() - INTERVAL 15 MINUTE",
			"FirstName, Surname",
			"");
	}


	/**
	 * Get the forum holder controller
	 *
	 * @return ForumHolder_Controller Returns the forum holder controller.
	 */
	function _ForumHolder() {
		return new ForumHolder_Controller(DataObject::get_one("ForumHolder"));
	}


	/**
	 * Get the forum holder's total posts
	 *
	 * @return int Returns the number of total posts
	 *
	 * @see ForumHolder::TotalPosts()
	 */
	function TotalPosts() {
		return $this->ForumHolder()->TotalPosts();
	}


	/**
	 * Get the number of total topics (threads) of the forum holder
	 *
	 * @see ForumHolder::TotalTopics()
	 */
	function TotalTopics() {
		return $this->ForumHolder()->TotalTopics();
	}


	/**
	 * Get the number of distinct authors of the forum holder
	 *
	 * @see ForumHolder::TotalAuthors()
	 */
	function TotalAuthors() {
		return $this->ForumHolder()->TotalAuthors();
	}


	/**
	 * Get the forums
	 *
	 * @see ForumHolder::Forums()
	 */
	function Forums() {
		return $this->ForumHolder()->Forums();
	}


	/**
	 * Returns the search results
	 *
	 * @see ForumHolder::SearchResults()
	 */
	function SearchResults() {
		return $this->ForumHolder()->SearchResults();
	}


	/**
	 * Get a subtitle
	 */
	function getSubtitle() {
		return "User profile";
	}


	/**
	 * Get the forum holders' abstract
	 *
	 * @see ForumHolder::getAbstract()
	 */
	function getAbstract() {
		return $this->ForumHolder()->getAbstract();
	}


	/**
	 * Get the URL segment of the forum holder
	 *
	 * @see ForumHolder::URLSegment()
	 */
	function URLSegment() {
		return $this->ForumHolder()->URLSegment();
	}


	/**
	 * This needs MetaTags because it doesn't extend SiteTree at any point
	 */
	function MetaTags($includeTitle = true) {
		$tags = "";
		$Title = "Forum user profile";
		if($includeTitle == true) {
			$tags .= "<title>" . $Title . "</title>\n";
		}

		return $tags;
	}
}


?>