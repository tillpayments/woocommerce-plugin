import { useEffect, useRef, useState } from "@wordpress/element";
import { decodeEntities } from "@wordpress/html-entities";
import "./style.css";

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting("till_payments_creditcard_data", {});
const ccnDivId = "till_ccn_div";
const cvvDivId = "till_cvv_div";
const cardholderInput = "till_cardholder_name_input";
const expiryDateInput = "till_expiry_date_input";

let payment = null;
let tokenG = "";
let cardDataG = {};

const FormInputText = ({ label, id, value, onChange }) => {
  return (
    <div className="wc-block-components-text-input">
      {/* <label className="wc-block-components-text-input__label" htmlFor={id}>
				{label}
			</label> */}
      <input
        type="text"
        // className="wc-block-components-text-input__input"
        id={id}
        name={id}
        value={value}
        onChange={onChange}
        placeholder={label}
      />
    </div>
  );
};

const Spinner = () => (
  <div className="spinner-container">
    <div className="spinner" />
    <p>Loading card payment fields ...</p>
  </div>
);

const label = decodeEntities(settings.title);

const Content = (props) => {
  const { eventRegistration, emitResponse } = props;
  const { onPaymentSetup, onCheckoutValidation } = eventRegistration;
  const [paymentJsLoaded, setPaymentJsLoaded] = useState(
    window.PaymentJs === undefined
  );
  const [paymentJsInitialised, setPaymentJsInitialised] = useState(false);
  const [retryCount, setRetryCount] = useState(0);
  const maxRetry = 3;
  const formRef = useRef(null);

  useEffect(() => {
    if (window.PaymentJs && formRef) {
      const el = document.getElementById(cardholderInput);
      const blockStyle = window.getComputedStyle(el);

      const baseStyle = {
        "background-color": blockStyle.backgroundColor,
        height: blockStyle.height,
        "max-height": blockStyle.maxHeight,
        "font-family": blockStyle.fontFamily,
        "font-size": blockStyle.fontSize,
        "font-weight": blockStyle.fontWeight,
        border: blockStyle.border,
        // width: blockStyle.width, // adopt from iframe wrapper container
        "border-radius": blockStyle.borderRadius,
        padding: blockStyle.padding,
        margin: blockStyle.margin,
        color: blockStyle.color,
      };

      document.getElementById(ccnDivId).style.height = blockStyle.height;
      document.getElementById(cvvDivId).style.height = blockStyle.height;

      payment = new window.PaymentJs();
      payment.init(
        settings.integrationKey,
        ccnDivId,
        cvvDivId,
        function (payment) {
          payment.setNumberStyle(baseStyle);
          payment.setCvvStyle(baseStyle);
          payment.setNumberPlaceholder("Card number");
          payment.setCvvPlaceholder("CVC");
          payment.numberOn("blur", function (data) {
            if (!data.validNumber) {
              payment.setNumberStyle({ ...baseStyle, "border-color": "red" });
            }
          });
          payment.numberOn("input", function (data) {
            if (data.validNumber) {
              payment.setNumberStyle(baseStyle);
            }
          });

          //   console.log(`payment #2: ${JSON.stringify(payment)}`);

          setPaymentJsInitialised(payment.initialized);
          //   console.log(`payment.js initialised ${payment.initialized}`);
        },
        function (error) {
          console.log(`Error with Initialising Payment.js ${error}`);
        }
      );
    } else {
      if (retryCount < maxRetry) {
        console.error("payment.js was not found");
        const timer = setTimeout(() => {
          setRetryCount((prev) => prev + 1);
        }, 1000);

        return () => clearTimeout(timer);
      } else {
        console.error(`Failed to load payment.js after ${retryCount} attempts`);
      }
    }
  }, [paymentJsLoaded, retryCount]);

  // Register validation and payment setup hooks
  useEffect(() => {
    const unsubscribeValidation = onCheckoutValidation(() => {
      return new Promise((resolve) => {
        let expiryValue = document.getElementById(expiryDateInput)?.value;
        let cardholderValue = document.getElementById(cardholderInput)?.value;

        if (!expiryValue || !cardholderValue) {
          resolve({
            type: emitResponse.responseTypes.ERROR,
            errorMessage: "Please fill in all required fields.",
          });
          return;
        }

        let [month, year] = expiryValue.split("/");
        let data = {
          card_holder: cardholderValue,
          month: month,
          year: year,
        };

        payment.tokenize(
          data,
          //success callback
          (token, cardData) => {
            if (token) {
              tokenG = token;
              cardDataG = cardData;
              resolve({
                type: emitResponse.responseTypes.SUCCESS,
              });
            }
          },
          // failure callback
          (errors) => {
            resolve({
              type: emitResponse.responseTypes.ERROR,
              errorMessage: errors[0]?.message || "Card validation failed",
            });
          }
        );
      });
    });

    const unsubscribePaymentSetup = onPaymentSetup(() => {
      return {
        type: emitResponse.responseTypes.SUCCESS,
        meta: {
          paymentMethodData: {
            token: tokenG,
            cardData: JSON.stringify(cardDataG),
          },
        },
      };
    });

    return () => {
      unsubscribeValidation();
      unsubscribePaymentSetup();
    };
  }, [onPaymentSetup, onCheckoutValidation, emitResponse]);

  const TestModeWarning = () => {
    if (settings.testMode === true) {
      return (
        <p className="test-mode-warning">
          <strong>Test mode</strong> is enabled. Use one of the test cards
          listed in our{" "}
          <a href="https://test-gateway.tillpayments.com/documentation/connectors#simulator-testing-connector-test-data-extended-3d-secure-testing">
            documentation
          </a>
        </p>
      );
    } else {
      return <></>;
    }
  };

  //   paymentJsInitialised currently reliant on #till_inline_checkout_form initialising payment.js
  //   to be decoupled
  return (
    <div className="wc-block-components-form">
      <p>{settings.description}</p>
      <TestModeWarning />

      {!paymentJsInitialised ? <Spinner /> : <></>}

      <form
        ref={formRef}
        id="till_inline_checkout_form"
        className={paymentJsInitialised ? "" : "hidden"}
      >
        <div>
          <div id={ccnDivId} className="wc-block-components-text-input"></div>
        </div>

        <div className="inline-container">
          <div className="half-width">
            <ExpiryDateInput />
          </div>
          <div id="half-container-cvv" className="half-width">
            <div id={cvvDivId} className="wc-block-components-text-input"></div>
          </div>
        </div>

        <FormInputText
          label="Name on card"
          id={cardholderInput}
          name={cardholderInput}
          onChange={null}
          isIframeField={true}
        />
      </form>
    </div>
  );
};

const Icon = () => {
  return settings.icon ? (
    <img
      src={settings.icon}
      style={{ float: "right", marginLeft: "20px", minHeight: "32px" }}
    />
  ) : (
    ""
  );
};

const Label = (props) => {
  const { PaymentMethodLabel } = props.components;
  return <PaymentMethodLabel text={label} icon={<Icon />} />;
};

const ExpiryDateInput = () => {
  const [expiry, setExpiry] = useState("");

  const handleChange = (e) => {
    let value = e.target.value.replace(/\D/g, ""); // Remove non-digits

    if (value.length > 4) value = value.slice(0, 4);

    if (value.length > 2) {
      value = value.slice(0, 2) + "/" + value.slice(2);
    }

    setExpiry(value);
  };

  return (
    <div className="wc-block-components-text-input">
      <input
        type="text"
        // className="wc-block-components-text-input__input"
        id={expiryDateInput}
        value={expiry}
        onChange={handleChange}
        placeholder="MM / YY"
        maxLength={5} // 4 digits + 1 slash
      />
    </div>
  );
};

registerPaymentMethod({
  name: "till_payments_creditcard",
  paymentMethodId: "till_payments_creditcard",
  label: <Label />,
  content: <Content />,
  edit: <Content />,
  canMakePayment: () => true,
  ariaLabel: label ? label : "payment form",
  supports: {
    features: settings.supports,
  },
});
