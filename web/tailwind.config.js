/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/views/**/*.blade.php', './resources/js/**/*.{vue,js,ts}'],
    // the design system in bookball.css styles the base elements; keep Tailwind's reset minimal
    corePlugins: { preflight: false },
    theme: {
        extend: {
            colors: {
                bg: 'var(--bg)', surface: 'var(--surface)', 'surface-2': 'var(--surface-2)', 'surface-3': 'var(--surface-3)',
                ink: 'var(--ink)', muted: 'var(--muted)', faint: 'var(--faint)', line: 'var(--line)',
                accent: 'var(--accent)', free: 'var(--free)', warn: 'var(--warn)', hot: 'var(--hot)', blue: 'var(--blue)',
            },
            fontFamily: { display: 'var(--display)', body: 'var(--body)', mono: 'var(--mono)' },
        },
    },
    plugins: [],
};
