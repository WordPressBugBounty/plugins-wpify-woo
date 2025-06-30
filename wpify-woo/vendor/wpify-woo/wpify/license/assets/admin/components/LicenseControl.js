import React from 'react';
import {Button} from '@wordpress/components';
import {__} from '@wordpress/i18n';
import './LicenseControl.scss';

const LicenseControl = () => {
  const activated = window.wpifyWooLicenseSettings.activated;
  const label = __('Status:', 'wpify-license') + ' ' + (
      activated
          ? __('Activated', 'wpify-license')
          : __('Not active', 'wpify-license')
  );
  const description = (
      activated
          ? __('Domain is connected with your subscription in WPify account.', 'wpify-license')
          : __('Please activate the domain by connecting it with your WPify account!', 'wpify-license')
  );
  const className = 'license-field ' + (activated ? 'active' : 'not-active');

  return (
      <div className={className}>
        <div className="license-field__text">
          <h3>{label}</h3>
          <p>{description}</p>
        </div>
        {activated
            ? <Button href={window.wpifyWooLicenseSettings.deactivateUrl}
                      isPrimary>{__('Deactivate domain', 'wpify-license')}</Button>
            : <Button href={window.wpifyWooLicenseSettings.activateUrl}
                      isPrimary>{__('Activate domain', 'wpify-license')}</Button>
        }
      </div>
  );
};

export default LicenseControl;
