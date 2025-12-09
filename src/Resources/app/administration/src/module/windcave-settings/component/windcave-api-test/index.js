import template from './windcave-api-test.html.twig';
import './windcave-api-test.scss';

const { Component, Mixin } = Shopware;

/**
 * Windcave API Test Component
 *
 * Allows admin users to test their API credentials before going live.
 */
Component.register('windcave-api-test', {
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
            testResult: null,
            lastTestTime: null
        };
    },

    computed: {
        testResultVariant() {
            if (!this.testResult) return 'neutral';
            return this.testResult.success ? 'success' : 'danger';
        },

        testResultIcon() {
            if (!this.testResult) return 'default-basic-stack-circle';
            return this.testResult.success
                ? 'default-basic-checkmark-circle'
                : 'default-badge-error';
        }
    },

    methods: {
        async testCredentials() {
            this.isLoading = true;
            this.testResult = null;

            try {
                const response = await this.httpClient.post(
                    '/api/windcave/test-credentials',
                    {
                        salesChannelId: this.salesChannelId
                    }
                );

                this.testResult = response.data;
                this.lastTestTime = new Date().toLocaleTimeString();

                if (this.testResult.success) {
                    this.createNotificationSuccess({
                        title: 'API Test Successful',
                        message: this.testResult.message
                    });
                } else {
                    this.createNotificationError({
                        title: 'API Test Failed',
                        message: this.testResult.message
                    });
                }

            } catch (error) {
                this.testResult = {
                    success: false,
                    message: error.response?.data?.message || 'Failed to connect to API'
                };

                this.createNotificationError({
                    title: 'API Test Error',
                    message: this.testResult.message
                });
            } finally {
                this.isLoading = false;
            }
        }
    },

    created() {
        this.httpClient = Shopware.Application.getContainer('init').httpClient;
    }
});
