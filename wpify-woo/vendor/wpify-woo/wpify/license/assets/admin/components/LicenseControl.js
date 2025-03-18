import React from 'react';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import './LicenseControl.scss';

const LicenseControl = () => {
  const activated = window.wpifyWooLicenseSettings.activated;
  const label = __('Status:', 'wpify-woo') + ' ' + (
    activated
      ? __('Activated', 'wpify-woo')
      : __('Not active', 'wpify-woo')
  );

  return (
    <>
      <h3>{label}</h3>
      {activated
        ? <Button href={window.wpifyWooLicenseSettings.deactivateUrl} isPrimary>{__('Deactivate domain', 'wpify-woo')}</Button>
        : <Button href={window.wpifyWooLicenseSettings.activateUrl} isPrimary>{__('Activate domain', 'wpify-woo')}</Button>
      }
    </>
  );
};

export default LicenseControl;
