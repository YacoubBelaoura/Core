<template>
    <div v-click-outside="() => visible = false"
        :class="[
            'navbar-item notifications',
            { 'has-dropdown': !isTouch },
            { 'is-active': visible }
        ]">
        <span v-if="isTouch" class="is-clickable"
             @click="$router.push({'name': 'core.notifications.index'})">
            <span class="icon">
                <fa icon="bell"/>
            </span>
            <sup class="has-text-danger notification-count">
                {{ unreadCount || null }}
            </sup>
        </span>
        <a v-else
            :class="['navbar-link', { 'rotate': visible }]"
            @click="toggle()">
            <span class="icon">
                <fa icon="bell"/>
            </span>
            <sup class="has-text-danger notification-count">
                {{ unreadCount || null }}
            </sup>
            <overlay v-if="loading"/>
        </a>
        <div v-if="visible"
            class="navbar-dropdown " :class="isRTL ? 'is-left' : 'is-right'">
            <div class="notification-list"
                @scroll="computeScrollPosition($event)">
                <a v-for="notification in notifications"
                    :key="notification.id"
                    class="navbar-item"
                    @click="update(notification)">
                    <div class="navbar-content">
                        <p class="is-notification" :class="{ 'is-bold': !notification.read_at }">
                            <fa v-if="notification.data.icon"
                                :icon="notification.data.icon"/>
                            {{ notification.data.body }}
                        </p>
                        <p>
                            <small class="has-text-info">
                                {{ timeFromNow(notification.created_at) }}
                            </small>
                        </p>
                    </div>
                </a>
            </div>
            <hr v-if="notifications.length"
                class="navbar-divider">
            <nav v-if="notifications.length"
                class="level navbar-item">
                <div class="level-left">
                    <div class="level-item">
                        <a class="button is-small is-info has-margin-left-small"
                            @click="
                                visible = false;
                                $router.push({'name': 'core.notifications.index'})
                            ">
                            <span>{{ __("See all") }}</span>
                            <span class="icon is-small">
                                <fa icon="eye"/>
                            </span>
                        </a>
                    </div>
                </div>
                <div class="level-right">
                    <div class="level-item">
                        <a class="button is-small is-success"
                            @click="updateAll">
                            <span>{{ __("Mark all as read") }}</span>
                            <span class="icon is-small">
                                <fa icon="check"/>
                            </span>
                        </a>
                    </div>
                </div>
            </nav>
            <a v-else class="navbar-item">
                <span v-if="unreadCount || loading">
                    {{ __("Loading...") }}
                </span>
                <span v-else-if="!unreadCount">
                    {{ __("You don't have any notifications") }}
                </span>
            </a>
        </div>
    </div>
</template>

<script>

import debounce from 'lodash/debounce';
import { mapState,mapGetters } from 'vuex';
import vClickOutside from 'v-click-outside';
import Pusher from 'pusher-js';
import Echo from 'laravel-echo';
import Favico from 'favico.js';
import { library } from '@fortawesome/fontawesome-svg-core';
import {
    faBell, faCheck, faEye, faCogs, faQuestion,
} from '@fortawesome/free-solid-svg-icons';
import Overlay from '../../../components/enso/bulma/Overlay.vue';
import format from '../../../modules/enso/plugins/date-fns/format';
import formatDistance from '../../../modules/enso/plugins/date-fns/formatDistance';

import './icons';

library.add(faBell, faCheck, faEye, faCogs, faQuestion);

export default {
    name: 'Notifications',

    directives: {
        clickOutside: vClickOutside.directive,
    },

    components: { Overlay },

    data: () => ({
        favico: new Favico({
            animation: 'popFade',
        }),
        limit: 200,
        notifications: [],
        unreadCount: 0,
        totalCount: 0,
        needsUpdate: true,
        offset: 0,
        loading: false,
        echo: null,
        visible: false,
        desktopNotifications: false,
    }),

    computed: {
        ...mapState(['user', 'meta']),
        ...mapState('layout', ['isTouch']),
        ...mapGetters('preferences', ['isRTL']),
    },

    watch: {
        unreadCount(val) {
            this.favico.badge(val);
        },
    },

    created() {
        this.fetch = debounce(this.fetch, 500);
        this.initEcho();
        this.initDesktopNotification();
        this.count();
        this.listen();
        this.addBusListeners();
    },

    methods: {
        toggle() {
            this.visible = !this.visible;

            if (this.visible) {
                this.fetch();
            }
        },
        count() {
            axios.get(route('core.notifications.count'))
                .then(({ data }) => {
                    this.unreadCount = data.count;
                }).catch(error => this.handleError(error));
        },
        fetch() {
            if (!this.needsUpdate || this.loading) {
                return;
            }

            this.loading = true;

            axios.get(route('core.notifications.index'), {
                params: { offset: this.offset, limit: this.limit },
            }).then(({ data }) => {
                this.notifications = this.offset ? this.notifications.concat(data) : data;
                this.offset = this.notifications.length;
                this.needsUpdate = false;
                this.loading = false;
            }).catch(error => this.handleError(error));
        },
        update(notification) {
            axios.patch(route('core.notifications.update', notification.id))
                .then(({ data }) => {
                    this.unreadCount = Math.max(--this.unreadCount, 0);
                    notification.read_at = data.read_at;

                    if (notification.data.path && notification.data.path !== '#') {
                        this.$router.push({ path: notification.data.path });
                    }
                }).catch(error => this.handleError(error));
        },
        updateAll() {
            axios.post(route('core.notifications.updateAll'))
                .then(() => this.readAll())
                .catch(error => this.handleError(error));
        },
        readAll() {
            this.notifications
                .filter(notification => !notification.read_at)
                .forEach(notification => (notification.read_at = this.now()));

            this.unreadCount = 0;
        },
        initEcho() {
            this.echo = new Echo({
                broadcaster: 'pusher',
                key: this.meta.pusher,
                cluster: this.meta.pusherCluster,
                namespace: 'App.Events',
            });
        },
        initDesktopNotification() {
            if (!('Notification' in window) || Notification.permission === 'denied') {
                return;
            }

            if (Notification.permission !== 'granted') {
                Notification.requestPermission((permission) => {
                    if (!('permission' in Notification)) {
                        Notification.permission = permission;
                    }

                    this.desktopNotifications = permission === 'granted';
                });
                return;
            }

            this.desktopNotifications = Notification.permission === 'granted';
        },
        listen() {
            const self = this;
            this.echo.private(`App.User.${this.user.id}`)
                .notification(({ level, body, title }) => {
                    self.unreadCount++;
                    self.needsUpdate = true;
                    self.offset = 0;

                    if (!document.hidden) {
                        this.$toastr[level](body, title);
                        return;
                    }

                    if (this.desktopNotifications) {
                        const notification = new Notification(title, {
                            body,
                        });

                        notification.onclick = () => {
                            window.focus();
                        };

                        window.navigator.vibrate(500);
                    }
                });
        },
        computeScrollPosition(event) {
            const a = event.target.scrollTop;
            const b = event.target.scrollHeight - event.target.clientHeight;

            if (a / b > 0.7) {
                this.needsUpdate = true;
                this.fetch();
            }
        },
        timeFromNow(date) {
            return formatDistance(date);
        },
        addBusListeners() {
            this.$root.$on('read-notification', (notification) => {
                this.unreadCount = Math.max(--this.unreadCount, 0);
                const existing = this.notifications
                    .find(({ id }) => id === notification.id);

                if (existing) {
                    existing.read_at = notification.read_at;
                }
            });

            this.$root.$on('read-all-notifications', () => this.readAll());

            this.$root.$on('destroy-notification', (notification) => {
                const index = this.notifications
                    .findIndex(({ id }) => id === notification.id);

                if (!notification.read_at) {
                    this.unreadCount = Math.max(--this.unreadCount, 0);
                }

                if (index >= 0) {
                    this.notifications.splice(index, 1);
                }
            });

            this.$root.$on('destroy-all-notifications', () => {
                this.notifications = [];
                this.unreadCount = 0;
            });
        },
        now() {
            return format(new Date());
        },
    },
};

</script>

<style lang="scss" scoped>

    sup.notification-count {
        font-size: 0.75em;
        margin-top: -10px;
    }

    div.notification-list {
        width: 300px;
        overflow-x: hidden;
        max-height: 400px;
        overflow-y: auto;
    }

    p.is-notification {
        white-space: normal;
        width: 268px;
        overflow-x: hidden;
    }

    .navbar-link {
        &:after {
            transform: rotate(135deg);
            transition: transform .300s ease;
        }

        &.rotate:after {
            transform: rotate(-45deg);
        }
    }

</style>
