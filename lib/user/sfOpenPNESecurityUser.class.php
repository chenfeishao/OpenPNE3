<?php

/**
 * sfOpenPNESecurityUser will handle credential for OpenPNE.
 *
 * @package    OpenPNE
 * @subpackage user
 * @author     Kousuke Ebihara <ebihara@tejimaya.com>
 */
class sfOpenPNESecurityUser extends sfBasicSecurityUser
{
  protected $authAdapter = null;
  protected $authForm = null;

  /**
   * Initializes the current user.
   *
   * @see sfBasicSecurityUser
   */
  public function initialize(sfEventDispatcher $dispatcher, sfStorage $storage, $options = array())
  {
    parent::initialize($dispatcher, $storage, $options);

    $request = sfContext::getInstance()->getRequest();
    $authMode = $request->getUrlParameter('authMode');
    if ($authMode)
    {
      $this->setCurrentAuthMode($authMode);
    }

    $containerClass = self::getAuthAdapterClassName($this->getCurrentAuthMode());
    $this->authAdapter = new $containerClass($this->getCurrentAuthMode());
    $this->authForm = $this->authAdapter->getAuthForm();

    $this->initializeCredentials();
  }

  public function getAuthModes()
  {
    return OpenPNEConfig::get(sfConfig::get('sf_app').'_auth_mode');
  }

  public function getAuthAdapter()
  {
    return $this->authAdapter;
  }

  public function getAuthForm()
  {
    return $this->authForm;
  }

  public function getAuthForms()
  {
    $result = array();

    $authModes = $this->getAuthModes();
    foreach ($authModes as $authMode) {
      $adapterClass = self::getAuthAdapterClassName($authMode);
      $adapter = new $adapterClass($authMode);
      $result[] = $adapter->getAuthForm();
    }

    return $result;
  }

  public static function getAuthAdapterClassName($authMode)
  {
    return 'opAuthAdapter'.ucfirst($authMode);
  }

  public function getMemberId()
  {
    return $this->getAttribute('member_id', null, 'sfOpenPNESecurityUser');
  }

  public function setMemberId($memberId)
  {
    return $this->setAttribute('member_id', $memberId, 'sfOpenPNESecurityUser');
  }

  public function setCurrentAuthMode($authMode)
  {
    $this->setAttribute('auth_mode', $authMode, 'sfOpenPNESecurityUser');
  }

  public function getCurrentAuthMode()
  {
    $authMode = $this->getAttribute('auth_mode', null, 'sfOpenPNESecurityUser');

    $authModes = $this->getAuthModes();
    if (!in_array($authMode, $authModes))
    {
      $authMode = array_shift($authModes);
      $this->setCurrentAuthMode($authMode);
    }

    return $authMode;
  }

  public function getMember()
  {
    return MemberPeer::retrieveByPk($this->getMemberId());
  }

  public function getRegisterEndAction()
  {
    return $this->getAuthAdapter()->getRegisterEndAction();
  }

 /**
  * Login
  *
  * @return bool   returns true if the current user is authenticated, false otherwise
  */
  public function login()
  {
    $memberId = $this->getAuthAdapter()->authenticate();

    if ($memberId)
    {
      $this->setMemberId($memberId);
      $this->setAuthenticated(true);
    }

    $this->initializeCredentials();

    if ($this->isAuthenticated())
    {
      $uri = $this->getAuthAdapter()->getAuthForm()->getValue('next_uri');
      return $uri;
    }

    return false;
  }

 /**
  * Logout
  */
  public function logout()
  {
    $this->setAuthenticated(false);
    $this->getAttributeHolder()->removeNamespace('sfOpenPNESecurityUser');
    $this->clearCredentials();
  }

 /**
  * Registers the current user with OpenPNE
  *
  * @param  sfForm $form
  * @return bool   returns true if the current user is authenticated, false otherwise
  */
  public function register($form = null)
  {
    $result = $this->getAuthAdapter()->register($form);
    if ($result) {
      $this->setAuthenticated(true);
      $this->setAttribute('member_id', $result, 'sfOpenPNESecurityUser');
      return true;
    }

    return false;
  }

 /**
  * Initializes all credentials associated with this user.
  */
  public function initializeCredentials()
  {
    $memberId = $this->getMemberId();
    $isRegisterFinish = $this->getAuthAdapter()->isRegisterFinish($memberId);
    $isRegisterBegin = $this->getAuthAdapter()->isRegisterBegin($memberId);

    $this->setIsSNSMember(false);
    $this->setIsSNSRegisterBegin(false);
    $this->setIsSNSRegisterFinish(false);

    if (!$this->getMember())
    {
      return false;
    }

    if ($memberId && $isRegisterFinish)
    {
      $this->setIsSNSRegisterFinish(true);
    }
    elseif ($isRegisterBegin)
    {
      $this->setIsSNSRegisterBegin(true);
    }
    elseif ($memberId)
    {
      $this->setIsSNSMember(true);
    }
  }

  public function setIsSNSMember($isSNSMember)
  {
    if ($isSNSMember) {
      $this->setAuthenticated(true);
      $this->addCredential('SNSMember');
    } else {
      $this->removeCredential('SNSMember');
    }
  }

  public function setIsSNSRegisterBegin($isSNSRegisterBegin)
  {
    if ($this->hasCredential('SNSMember')) {
      $this->removeCredential('SNSRegisterBegin');
      return false;
    }

    if ($isSNSRegisterBegin) {
      $this->setAuthenticated(true);
      $this->addCredential('SNSRegisterBegin');
    } else {
      $this->removeCredential('SNSRegisterBegin');
    }
  }

  public function setIsSNSRegisterFinish($isSNSRegisterFinish)
  {
    if ($this->hasCredential('SNSMember')) {
      $this->removeCredential('SNSRegisterFinish');
      return false;
    }

    if ($isSNSRegisterFinish) {
      $this->setAuthenticated(true);
      $this->addCredential('SNSRegisterFinish');
    } else {
      $this->removeCredential('SNSRegisterFinish');
    }
  }


 /**
  * @deprecated This method overrides sfBasicSecurityUser::removeCredential() bacause
  * we want to remove a function call sfSessionStorage::regenerate() for CSRF protection
  * becomes working correctly.
  * But this solution is a very stupid. We must decrease this function call.
  */
  public function removeCredential($credential)
  {
    if ($this->hasCredential($credential))
    {
      foreach ($this->credentials as $key => $value)
      {
        if ($credential == $value)
        {
          if ($this->options['logging'])
          {
            $this->dispatcher->notify(new sfEvent($this, 'application.log', array(sprintf('Remove credential "%s"', $credential))));
          }

          unset($this->credentials[$key]);

          return;
        }
      }
    }
  }

 /**
  * @deprecated This method overrides sfBasicSecurityUser::addCredentials() bacause
  * we want to remove a function call sfSessionStorage::regenerate() for CSRF protection
  * becomes working correctly.
  * But this solution is a very stupid. We must decrease this function call.
  */
  public function addCredentials()
  {
    if (func_num_args() == 0) return;

    // Add all credentials
    $credentials = (is_array(func_get_arg(0))) ? func_get_arg(0) : func_get_args();

    if ($this->options['logging'])
    {
      $this->dispatcher->notify(new sfEvent($this, 'application.log', array(sprintf('Add credential(s) "%s"', implode(', ', $credentials)))));
    }

    $added = false;
    foreach ($credentials as $aCredential)
    {
      if (!in_array($aCredential, $this->credentials))
      {
        $added = true;
        $this->credentials[] = $aCredential;
      }
    }
  }
}
