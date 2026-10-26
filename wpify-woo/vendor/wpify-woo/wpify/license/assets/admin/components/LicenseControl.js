import React, {useEffect, useState} from 'react';
import {Button} from '@wordpress/components';
import {__, sprintf} from '@wordpress/i18n';
import { dateI18n, getSettings } from '@wordpress/date';

const LicenseControl = () => {
    const settings = window.wpifyWooLicenseSettings;
    const activated = settings.activated;
    const [licenseDetails, setLicenseDetails] = useState(null);
    // Activations stored before the server reported validity have no `valid` key and count as valid.
    const [licenceValid, setLicenceValid] = useState(activated?.valid !== false);
    const [status, setStatus] = useState(null);
    const dateSettings = getSettings();
    const formatDate = (value) => dateI18n(dateSettings.formats.date, value);

    // Only the server's validity is stored, so the dashboard and support page show the same.
    const saveValidity = (valid) => {
        const body = new URLSearchParams({
            action: settings.ajaxAction,
            _ajax_nonce: settings.ajaxNonce,
            status: JSON.stringify({valid}),
        });

        fetch(settings.ajaxUrl, {method: 'POST', credentials: 'same-origin', body}).catch(() => {
        });
    };

    const fetchLicenseDetails = () => {
        // Loaded in the browser so a slow or unavailable server never delays the settings page.
        fetch(settings.license_details_url)
            .then(response => response.json().then(data => ({response, data})))
            .then(({response, data}) => {
                if (response.status === 200) {
                    setLicenseDetails(data);
                }

                // Older servers send no status; the stored validity is then kept.
                const live = data?.wpify_license || data?.data?.wpify_license;
                if (!live) {
                    return;
                }

                setStatus(live);
                setLicenceValid(live.valid);

                if (live.valid !== (activated?.valid !== false)) {
                    saveValidity(live.valid);
                }
            })
            .catch(() => {
            });
    };

    useEffect(() => {
        // Without a license the details URL ends with an empty key and always 404s.
        if (activated?.license) {
            fetchLicenseDetails();
        }
    }, []);

    const reason = () => {
        switch (status?.code) {
            case 'domain-mismatch':
                return status.domain
                    ? sprintf(
                        /* translators: %s: domain the license is activated for */ __('The license is activated for the domain %s, not for this site.', 'wpify-license'),
                        status.domain
                    )
                    : __('The license is activated for a different domain.', 'wpify-license');
            case 'not-found':
                return __('The activation was not found in your WPify account. It may have been deactivated there.', 'wpify-license');
            case 'expired':
                if (status.subscription_status === 'on-hold') {
                    return __('The subscription is on hold, waiting for payment.', 'wpify-license');
                }

                return status.expires
                    ? sprintf(
                        /* translators: %s: date the subscription ended */ __('The subscription expired on %s.', 'wpify-license'),
                        formatDate(status.expires)
                    )
                    : __('The subscription is not active.', 'wpify-license');
            case 'no-subscription':
                return __('No active subscription was found for this license.', 'wpify-license');
        }

        return status?.message || __('The license is not valid, please go to your account and review your subscription .', 'wpify-license');
    };

    const licenceUuid = activated?.license || licenseDetails?.item?.uuid;

    // Renewal from the status; older servers only provide it in the license details.
    const subscriptionPay = status?.expires || licenseDetails?.subscription?.next_payment;
    const hasRenewal = subscriptionPay && subscriptionPay !== '0' && subscriptionPay !== 0;
    const showRenewal = licenceValid && (status?.expires || licenseDetails?.subscription);
    const formattedRenewal = hasRenewal ? formatDate(subscriptionPay) : __('Without renewal', 'wpify-license');

    // The activation is gone or belongs to another domain: activating again connects this site.
    const needsActivation = !activated || ['domain-mismatch', 'not-found'].includes(status?.code);

    const label = __('Status:', 'wpify-license') + ' ' + (
        activated
            ? (licenceValid === false ? __('Not valid', 'wpify-license') : __('Active', 'wpify-license'))
            : __('Not active', 'wpify-license')
    );
    const description = (
        activated
            ? __('Domain is connected with your subscription in WPify account.', 'wpify-license')
            : __('Please activate the domain by connecting it with your WPify account!', 'wpify-license')
    );
    const state = activated ? (licenceValid === false ? 'warning' : 'active') : 'not-active';
    const tone = {active: 'success', warning: 'warning', 'not-active': 'error'}[state];
    // State classes stay for older woo-core copies that style them.
    const className = `license-field ${state} wpify-notice wpify-notice-${tone}`;

    return (
        <div className={className}>
            <div className="license-field__text">
                <h3>{label}</h3>
                {licenceValid !== false && <p>{description}</p>}
                {licenceValid === false && <p>{reason()}</p>}
                {licenceUuid && <p>
                    {__('Key:', 'wpify-license') + ' ' + licenceUuid}
                </p>}
                {showRenewal && <p>
                    {__('Renewal:', 'wpify-license') + ' ' + formattedRenewal}
                </p>}
            </div>
            {needsActivation && <Button href={settings.activateUrl}
                                        isPrimary>{__('Activate domain', 'wpify-license')}</Button>}
            {/* Empty when the user may not release the licence (on multisite only a super admin may). */}
            {!needsActivation && settings.deactivateUrl && <Button href={settings.deactivateUrl}
                                                                   isPrimary>{__('Deactivate domain', 'wpify-license')}</Button>}
        </div>
    );
};

export default LicenseControl;
