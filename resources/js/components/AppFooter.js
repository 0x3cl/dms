import { defineComponent } from 'vue';
import { organization } from '../organization';

/**
 * The site footer. `[data-app-footer]` is what AdminShell looks for to pull
 * the fixed sidebar's bottom edge up while the footer is on screen — keep the
 * attribute if this markup is reworked.
 */
export default defineComponent({
    name: 'AppFooter',
    setup() {
        return { org: organization };
    },
    template: `
    <footer data-app-footer class="border-t border-sky-100 bg-linear-to-r from-[#f7fbff] via-[#eef7ff] to-[#f8fcff] text-[#4b78b5] print:hidden">
        <div class="mx-auto flex max-w-[1800px] flex-col gap-5 px-5 py-6 text-sm sm:px-8 lg:flex-row lg:items-center lg:justify-between lg:px-12">
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-8 sm:gap-y-4 lg:gap-x-10">
                <address class="flex items-center gap-3 not-italic">
                    <svg class="h-6 w-6 shrink-0 fill-[#3386d7]" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z" />
                    </svg>
                    <span>
                        <strong class="block font-bold">{{ org.orgName }}</strong>
                        <span class="text-xs">{{ org.address }}</span>
                    </span>
                </address>
                <a :href="'tel:' + org.phoneHref" class="flex items-center gap-2 font-medium hover:text-[#07559e]"><svg class="h-5 w-5 shrink-0 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.5 15.5 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.24 11.4 11.4 0 0 0 3.57.57 1 1 0 0 1 1 1V21a1 1 0 0 1-1 1C10.6 22 2 13.4 2 3a1 1 0 0 1 1-1h3.9a1 1 0 0 1 1 1 11.4 11.4 0 0 0 .57 3.57 1 1 0 0 1-.24 1Z" /></svg>{{ org.phoneLabel }}</a>
                <a :href="'mailto:' + org.email" class="flex items-center gap-2 font-medium hover:text-[#07559e]"><svg class="h-5 w-5 shrink-0 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm0 3 9 5 9-5V7l-9 5-9-5v1Z" /></svg>{{ org.email }}</a>
                <a :href="org.websiteUrl" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 font-medium hover:text-[#07559e]"><svg class="h-5 w-5 shrink-0 fill-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 9h-3.1a15.8 15.8 0 0 0-1.1-5A8 8 0 0 1 18.9 11ZM12 4c.9 1.3 1.8 3.4 2 7h-4c.2-3.6 1.1-5.7 2-7ZM9.3 6a15.8 15.8 0 0 0-1.1 5H5.1A8 8 0 0 1 9.3 6ZM5.1 13h3.1a15.8 15.8 0 0 0 1.1 5A8 8 0 0 1 5.1 13ZM12 20c-.9-1.3-1.8-3.4-2-7h4c-.2 3.6-1.1 5.7-2 7Zm2.7-2a15.8 15.8 0 0 0 1.1-5h3.1a8 8 0 0 1-4.2 5Z" /></svg>{{ org.websiteLabel }}</a>
            </div>
            <p class="border-t border-sky-200/80 pt-4 text-xs font-medium italic text-[#6f8fbb] lg:border-t-0 lg:border-l lg:pl-8 lg:pt-0">{{ org.tagline }}</p>
        </div>
    </footer>
`,
});
