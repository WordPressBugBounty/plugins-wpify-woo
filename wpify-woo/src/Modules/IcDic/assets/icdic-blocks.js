import {useDispatch, useSelect} from '@wordpress/data';
import {createRoot} from 'react-dom/client';
import {useEffect, useState} from "react";
import {createPortal} from 'react-dom';

const {CART_STORE_KEY, CHECKOUT_STORE_KEY, COLLECTIONS_STORE_KEY, VALIDATION_STORE_KEY} = window.wc.wcBlocksData;

export const useCart = () => {
	return useSelect((select) => {
		const store = select(CART_STORE_KEY);
		return store.getCartData();
	}, []);
};
export const useAdditionalFields = () => {
	return useSelect((select) => {
		const store = select(CHECKOUT_STORE_KEY);
		return store.getAdditionalFields();
	}, []);
};
export const useCustomerData = () => {
	return useSelect((select) => {
		const store = select(CART_STORE_KEY);
		return store.getCustomerData();
	}, []);
};

export const useCollections = () => {
	return useSelect((select) => {
		const store = select(COLLECTIONS_STORE_KEY);
		return store;
	}, []);
};
export const useValidationError = (id) => {
	return useSelect((select) => {
		const store = select(VALIDATION_STORE_KEY);
		return store.getValidationError(id);
	}, []);
};



const App = () => {
	const {extensionCartUpdate} = window.wc.blocksCheckout;
	const [aresAdded, setAresAdded] = useState(false)
	const [aresError, setAresError] = useState()
	const [isAresLoading, setIsAresLoading] = useState()
	const [viesError, setViesError] = useState()
	const [isViesLoading, setIsViesLoading] = useState()
	const cart = useCart();
	const customer = useCustomerData();
	const additionalFields = useAdditionalFields();
	const {setAdditionalFields} = useDispatch(CHECKOUT_STORE_KEY);
	const { showValidationError, setValidationErrors, showAllValidationErrors } = useDispatch( VALIDATION_STORE_KEY );
	const {setBillingAddress, setShippingAddress} = useDispatch(CART_STORE_KEY);

	const dicError = useValidationError('contact-wpify-dic');
	console.log(dicError);
	const companyFieldWrap = document.querySelector('.wc-block-components-address-form__wpify-company');
	const icFieldWrap = document.querySelector('.wc-block-components-address-form__wpify-ic');
	const dicFieldWrap = document.querySelector('.wc-block-components-address-form__wpify-dic');
	const dicDphFieldWrap = document.querySelector('.wc-block-components-address-form__wpify-dic-dph');
	const contactWrap = document.querySelector('.wc-block-components-address-form__wpify-ic');
	const aresWrap = document.querySelector('#wpify-ares');

	const companyField = document.querySelector('#contact-wpify-company');
	const icField = document.querySelector('#contact-wpify-ic');
	const dicField = document.querySelector('#contact-wpify-dic');
	const dicDphField = document.querySelector('#contact-wpify-dic-dph');

	useEffect(() => {
		if (!companyFieldWrap) {
			return;
		}

		if (dicDphFieldWrap) {
			dicDphFieldWrap.style.display = 'none';
		}

		if (!additionalFields?.['wpify/ic_dic_toggle']) {
			companyFieldWrap.style.display = 'none';
			icFieldWrap.style.display = 'none';
			dicFieldWrap.style.display = 'none';

			additionalFields['wpify/company'] = '';
			additionalFields['wpify/ic'] = '';
			additionalFields['wpify/dic'] = '';
			additionalFields['wpify/dic-dph'] = '';

			setAdditionalFields(additionalFields);

		} else {
			companyFieldWrap.style.display = 'block';
			icFieldWrap.style.display = 'block';
			dicFieldWrap.style.display = 'block';

			if (customer.billingAddress.company) {
				additionalFields['wpify/company'] = customer.billingAddress.company;
			}
		}

		if (additionalFields?.['wpify/ic_dic_toggle'] && customer.billingAddress.country === 'SK') {
			dicDphFieldWrap.style.display = 'block';
		}

		if (aresWrap && additionalFields?.['wpify/ic_dic_toggle'] && customer.billingAddress.country === 'CZ') {
			aresWrap.style.display = 'block';
		} else if (aresWrap) {
			aresWrap.style.display = 'none';
		}

	}, [additionalFields, companyField, icField, dicField, dicDphField, companyFieldWrap, icFieldWrap, dicFieldWrap, dicDphFieldWrap, customer]);


	useEffect(() => {
		if (!contactWrap) {
			return;
		}
		const div = document.createElement('div');
		div.id = 'wpify-ares';
		contactWrap.appendChild(div);
		setAresAdded(true);
	}, [contactWrap]);


	function normalizeDic(dic) {
		dic = dic.replace('', ' ');
		dic = dic.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();

		if (!dic.match(/^[A-Z]{2}/)) {
			return customer.billingAddress.country + dic;
		}

		return dic;
	}

	function normalizeIc(ic) {
		ic = ic.replace('', ' ');
		ic = ic.replace(/\D/g, '');

		return ic;
	}

	useEffect(() => {
		if (!icField || customer.billingAddress.country !== 'CZ' ) {
			return;
		}

		let typingTimeout;

		const handleIcInputChange = (e) => {
			clearTimeout(typingTimeout);
			typingTimeout = setTimeout(() => {
				const normalizedValue = normalizeIc(e.target.value);
				e.target.value = normalizedValue;
				additionalFields['wpify/ic'] = normalizedValue;
				setAdditionalFields(additionalFields);
				autofillAres();
			}, 2000);
		};

		icField.addEventListener('input', handleIcInputChange);

		return () => {
			clearTimeout(typingTimeout);
			icField.removeEventListener('input', handleIcInputChange);
		};
	}, [icField]);

	useEffect(() => {
		if (!dicField && !dicDphField) {
			return;
		}

		const activeField = customer.billingAddress.country === 'SK' ? dicDphField : dicField;
		let typingTimeout;

		const handleDicInputChange = (e) => {
			clearTimeout(typingTimeout);

			typingTimeout = setTimeout(() => {
				validateDic(normalizeDic(e.target.value));
			}, 2000);
		};

		activeField?.addEventListener('input', handleDicInputChange);

		return () => {
			clearTimeout(typingTimeout);
			activeField?.removeEventListener('input', handleDicInputChange);
		};
	}, [dicField, dicDphField, customer.billingAddress.country]);


	function fetchJson(url, options) {
		return new Promise((resolve, reject) => {
			fetch(url, options)
				.then(response => {
					if (response.ok) {
						response.json().then(resolve);
					} else {
						response.json().then(json => reject(json.message));
					}
				})
				.catch(reject);
		});
	}

	function validateDic(dic) {
		setViesError(null);
		setIsViesLoading(true);
		if (window.wpifyWooIcDic.restUrl) {
			fetchJson(window.wpifyWooIcDic.restUrl + '/icdic-vies?in=' + dic)
				.then(({validation = {}}) => {
					if (customer.billingAddress.country === 'SK') {
						additionalFields['wpify/dic-dph'] = dic;
					} else {
						additionalFields['wpify/dic'] = dic;
					}
					setAdditionalFields(additionalFields);

					extensionCartUpdate({
						namespace: 'wpify_ic_dic',
						data: {
							validation: validation,
							country: customer.billingAddress.country
						}
					})

					const evt = new CustomEvent("wpify_woo_ic_dic_vies_valid", {
						detail: {
							validation: validation,
						}
					});
					window.dispatchEvent(evt);
				})
				.catch(error => {
					setViesError(error);
				})
				.finally(() => {
					setIsViesLoading(false);
				});
		}
	}

	const autofillAres = () => {
		setAresError(null);
		setIsAresLoading(true);
		const ic = normalizeIc(additionalFields['wpify/ic']);
		fetchJson(window.wpifyWooIcDic.restUrl + '/icdic?in=' + ic)
			.then(({details = {}}) => {
				additionalFields['wpify/company'] = details.billing_company;
				additionalFields['wpify/ic'] = details.billing_ic;
				additionalFields['wpify/dic'] = details.billing_dic;

				setAdditionalFields(additionalFields);

				if (details.billing_dic) {
					validateDic(normalizeDic(details.billing_dic))
				}

				const address = {
					company: details.billing_company,
					address_1: details.billing_address_1,
					city: details.billing_city,
					postcode: details.billing_postcode,
				};

				setBillingAddress(address);
				setShippingAddress(address);
				const evt = new CustomEvent("wpify_woo_ic_dic_ares_autofilled", {
					detail: {
						details: details,
					}
				});
				window.dispatchEvent(evt);
			})
			.catch(error => {
				setAresError(error);
			})
			.finally(() => {
				setIsAresLoading(false);
			});

	}
	if (!aresAdded) {
		return null;
	}


	return (
		<div>
			{createPortal(
				<>
					<div>
						<input type="button" className="button wp-element-button" onClick={() => autofillAres()}
							   value={window.wpifyWooIcDic.searchAresText}
						/>
						{isAresLoading && <div>Loading</div>}
						{aresError && <div>{aresError}</div>}
					</div>
				</>,
				aresWrap
			)}
			{createPortal(
				<>
					<div>
						{isViesLoading && <div>Loading</div>}
						{viesError && <div>{viesError}</div>}
					</div>
				</>,
				customer.billingAddress.country === 'SK' ? dicDphFieldWrap : dicFieldWrap
			)}
		</div>
	);
}

document.querySelectorAll('[data-app="wpify-ic-dic"]').forEach(function (el) {
	const root = createRoot(el)
	root.render(<App/>);
});




