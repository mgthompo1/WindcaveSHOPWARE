import template from './windcave-webhook-manager.html.twig';
import './windcave-webhook-manager.scss';

const { Component, Mixin } = Shopware;

/**
 * Windcave Webhook Manager Component
 *
 * Displays webhook URL and configuration instructions for Windcave FPRN setup.
 */
Component.register('windcave-webhook-manager', {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        salesChannelId: {
            type: String,
            required: false,
            default: null
        }
    },

    data() {
        return {
            isLoading: false,
            isTesting: false,
            webhookData: null,
            testResult: null
        };
    },

    computed: {
        webhookUrl() {
            return this.webhookData?.webhook_url || '';
        },

        instructions() {
            return this.webhookData?.instructions || [];
        }
    },

    methods: {
        async loadWebhookStatus() {
            this.isLoading = true;

            try {
                const response = await this.httpClient.get('/api/windcave/webhook-status', {
                    params: {
                        salesChannelId: this.salesChannelId
                    }
                });

                this.webhookData = response.data;

            } catch (error) {
                this.createNotificationError({
                    title: 'Error',
                    message: 'Failed to load webhook configuration'
                });
            } finally {
                this.isLoading = false;
            }
        },

        async testWebhook() {
            this.isTesting = true;
            this.testResult = null;

            try {
                const response = await this.httpClient.post('/api/windcave/test-webhook', {
                    salesChannelId: this.salesChannelId
                });

                this.testResult = response.data;

                if (this.testResult.success) {
                    this.createNotificationSuccess({
                        title: 'Webhook Test Successful',
                        message: `Endpoint responded in ${this.testResult.response_time_ms}ms`
                    });
                } else {
                    this.createNotificationWarning({
                        title: 'Webhook Test Warning',
                        message: this.testResult.message
                    });
                }

            } catch (error) {
                this.testResult = {
                    success: false,
                    message: error.response?.data?.message || 'Webhook test failed'
                };

                this.createNotificationError({
                    title: 'Webhook Test Failed',
                    message: this.testResult.message
                });
            } finally {
                this.isTesting = false;
            }
        },

        async copyWebhookUrl() {
            try {
                await navigator.clipboard.writeText(this.webhookUrl);
                this.createNotificationSuccess({
                    message: 'Webhook URL copied to clipboard'
                });
            } catch (err) {
                this.createNotificationError({
                    message: 'Failed to copy URL'
                });
            }
        }
    },

    created() {
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
        this.loadWebhookStatus();
    }
});
