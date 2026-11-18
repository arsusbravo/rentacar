import { createRouter, createWebHistory } from 'vue-router';
import AdminLayout from '../layouts/AdminLayout.vue';

const routes = [
    {
        path: '/admin',
        component: AdminLayout,
        children: [
            { path: '', name: 'admin.dashboard', component: () => import('../pages/admin/Dashboard.vue') },
            { path: 'categories', name: 'admin.categories', component: () => import('../pages/admin/Categories.vue') },
            { path: 'classes', name: 'admin.classes', component: () => import('../pages/admin/Classes.vue') },
            { path: 'prices', name: 'admin.prices', component: () => import('../pages/admin/Prices.vue') },
            { path: 'extras', name: 'admin.extras', component: () => import('../pages/admin/Extras.vue') },
            { path: 'bookings', name: 'admin.bookings', component: () => import('../pages/admin/Bookings.vue') },
            { path: 'customers', name: 'admin.customers', component: () => import('../pages/admin/Customers.vue') },
            { path: 'users', name: 'admin.users', component: () => import('../pages/admin/Users.vue') },
            { path: 'profile', name: 'admin.profile', component: () => import('../pages/admin/Profile.vue') },
            { path: 'locations', name: 'admin.branches', component: () => import('../pages/admin/Branches.vue') },
            { path: ':pathMatch(.*)*', redirect: { name: 'admin.dashboard' } },
        ],
    },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
