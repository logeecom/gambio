(function() {
    document.addEventListener("DOMContentLoaded", function () {

        let cardWrapper = document.querySelector('.mollie-component-wrapper');
        let paymentMethods = document.querySelectorAll('li.list-group-item');

        if (isCreditCardMethod()) {
            setTimeout(mountIfActive, 100);
        }

        for (let i = 0; i < paymentMethods.length; i++) {
            paymentMethods[i].onchange =  function (event) {

                let target = event.target;
                if (target.value === 'mollie_creditcard') {
                    setTimeout(mountIfActive, 100);
                } else {
                    MollieComponents.creditCard.unmount();
                }
            }
        }

        function isCreditCardMethod()
        {
            let selectedMethod = document.querySelector('input[name=payment]:checked');
            if (selectedMethod) {
                return selectedMethod.value === 'mollie_creditcard';
            }

            return false;
        }

        function mountIfActive() {
            let creditCardWrapper = document.querySelector('.mollie_creditcard');
            if (creditCardWrapper && creditCardWrapper.classList.contains('active')) {
                MollieComponents.creditCard.mount(cardWrapper);
            }
        }
    });
})();