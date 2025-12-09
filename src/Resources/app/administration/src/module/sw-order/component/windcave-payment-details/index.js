import template from './windcave-payment-details.html.twig';
import './windcave-payment-details.scss';

const { Component } = Shopware;

/**
 * Windcave Payment Details Component
 *
 * Displays Windcave payment transaction details in the order view.
 * Shows transaction ID, amounts, status, card tokens, and refund information.
 */
Component.register('windcave-payment-details', {
    template,

    props: {
        order: {
            type: Object,
            required: true
        }
    },

    computed: {
        /**
         * Get the Windcave transaction from the order's transactions
         */
        windcaveTransaction() {
            if (!this.order || !this.order.transactions) {
                return null;
            }

            // Find transaction with Windcave custom fields
            return this.order.transactions.find(transaction => {
                const customFields = transaction.customFields || {};
                return customFields.windcaveSessionId || customFields.windcaveTransactionId;
            });
        },

        /**
         * Check if this is a Windcave payment
         */
        isWindcavePayment() {
            return !!this.windcaveTransaction;
        },

        /**
         * Get Windcave custom fields from the transaction
         */
        windcaveData() {
            if (!this.windcaveTransaction) {
                return {};
            }
            return this.windcaveTransaction.customFields || {};
        },

        /**
         * Get formatted transaction details for display
         */
        transactionDetails() {
            const data = this.windcaveData;
            const details = [];

            if (data.windcaveTransactionId) {
                details.push({
                    label: 'Transaction ID',
                    value: data.windcaveTransactionId,
                    copyable: true
                });
            }

            if (data.windcaveSessionId) {
                details.push({
                    label: 'Session ID',
                    value: data.windcaveSessionId,
                    copyable: true
                });
            }

            if (data.windcaveAmount) {
                const currency = data.windcaveCurrency || 'NZD';
                details.push({
                    label: 'Amount',
                    value: `${data.windcaveAmount} ${currency}`,
                    copyable: false
                });
            }

            if (data.windcaveCurrency) {
                details.push({
                    label: 'Currency',
                    value: data.windcaveCurrency,
                    copyable: false
                });
            }

            return details;
        },

        /**
         * Get refund information if available
         */
        refundDetails() {
            const data = this.windcaveData;
            const details = [];

            if (data.windcaveRefundTransactionId) {
                details.push({
                    label: 'Refund Transaction ID',
                    value: data.windcaveRefundTransactionId,
                    copyable: true
                });
            }

            if (data.windcaveRefundAmount) {
                const currency = data.windcaveCurrency || 'NZD';
                details.push({
                    label: 'Refund Amount',
                    value: `${data.windcaveRefundAmount} ${currency}`,
                    copyable: false
                });
            }

            if (data.windcaveRefundedAt) {
                details.push({
                    label: 'Refunded At',
                    value: new Date(data.windcaveRefundedAt).toLocaleString(),
                    copyable: false
                });
            }

            return details;
        },

        /**
         * Check if there's refund data
         */
        hasRefundData() {
            return this.refundDetails.length > 0;
        },

        /**
         * Get card token information if stored
         */
        cardTokenDetails() {
            const data = this.windcaveData;
            const details = [];

            // Card type (Visa, Mastercard, etc.)
            if (data.windcaveCardType) {
                details.push({
                    label: 'Card Type',
                    value: data.windcaveCardType,
                    copyable: false
                });
            }

            // Last 4 digits
            if (data.windcaveCardLast4) {
                details.push({
                    label: 'Card Number',
                    value: `•••• ${data.windcaveCardLast4}`,
                    copyable: false
                });
            }

            // Expiry date
            if (data.windcaveCardExpiry) {
                details.push({
                    label: 'Card Expiry',
                    value: data.windcaveCardExpiry,
                    copyable: false
                });
            }

            // Card token (for recurring payments)
            if (data.windcaveCardId) {
                details.push({
                    label: 'Card Token',
                    value: this.maskCardToken(data.windcaveCardId),
                    copyable: true,
                    fullValue: data.windcaveCardId
                });
            }

            return details;
        },

        /**
         * Check if there's card data to display
         */
        hasCardTokenData() {
            const data = this.windcaveData;
            return !!(data.windcaveCardType || data.windcaveCardLast4 || data.windcaveCardExpiry || data.windcaveCardId);
        },

        /**
         * Get test/live mode indicator
         */
        isTestMode() {
            return this.windcaveData.windcaveDropInTestMode === true;
        },

        /**
         * Get payment state label
         */
        paymentStateLabel() {
            if (!this.windcaveTransaction || !this.windcaveTransaction.stateMachineState) {
                return 'Unknown';
            }
            return this.windcaveTransaction.stateMachineState.name;
        },

        /**
         * Get state variant for badge styling
         */
        stateVariant() {
            const state = this.windcaveTransaction?.stateMachineState?.technicalName;
            switch (state) {
                case 'paid':
                    return 'success';
                case 'authorized':
                    return 'info';
                case 'refunded':
                case 'partially_refunded':
                    return 'warning';
                case 'cancelled':
                case 'failed':
                    return 'danger';
                default:
                    return 'neutral';
            }
        }
    },

    methods: {
        /**
         * Mask card token for display (show first 6 and last 4)
         */
        maskCardToken(token) {
            if (!token || token.length < 10) {
                return token;
            }
            return `${token.slice(0, 6)}...${token.slice(-4)}`;
        },

        /**
         * Copy value to clipboard
         */
        async copyToClipboard(value) {
            try {
                await navigator.clipboard.writeText(value);
                this.createNotificationSuccess({
                    message: 'Copied to clipboard'
                });
            } catch (err) {
                this.createNotificationError({
                    message: 'Failed to copy to clipboard'
                });
            }
        }
    }
});
