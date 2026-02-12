<?php
/**
 * Generate 4 test pages via PressGo plugin on wp.pressgo.app.
 * Run: wp eval-file /tmp/generate-test-pages.php --allow-root
 *
 * Layout variant assignments for max diversity:
 *   Page 1 (Fitness):    centered hero, card features, default testimonials, gradient CTA
 *   Page 2 (SaaS PM):    split hero, alternating features, featured testimonial, gradient CTA
 *   Page 3 (Restaurant): image hero, card features, default testimonials, card CTA
 *   Page 4 (ReviewBoost): split hero, card features, featured testimonials, card CTA + competitive_edge image
 */

$plugin_dir = '/var/www/wp.pressgo.app/htdocs/wp-content/plugins/pressgo/';
require_once $plugin_dir . 'includes/generator/class-pressgo-element-factory.php';
require_once $plugin_dir . 'includes/generator/class-pressgo-style-utils.php';
require_once $plugin_dir . 'includes/generator/class-pressgo-widget-helpers.php';
require_once $plugin_dir . 'includes/generator/class-pressgo-section-builder.php';
require_once $plugin_dir . 'includes/generator/class-pressgo-generator.php';
require_once $plugin_dir . 'includes/class-pressgo-config-validator.php';
require_once $plugin_dir . 'includes/class-pressgo-page-creator.php';
require_once $plugin_dir . 'includes/class-pressgo.php';

// ── Page 1: Fitness Studio ──
// Hero: centered (default), Features: card grid, Testimonials: default 3-card, CTA: gradient
$fitness_config = array(
    'colors' => array(
        'primary' => '#FF6B35', 'dark_bg' => '#1A1A2E', 'light_bg' => '#FFF8F5',
        'white' => '#FFFFFF', 'text_dark' => '#1A1A2E', 'text_muted' => '#6B7280',
        'text_light' => 'rgba(255,255,255,0.7)', 'accent' => '#10B981', 'gold' => '#F59E0B',
        'border' => 'rgba(0,0,0,0.08)',
    ),
    'fonts' => array( 'heading' => 'Poppins', 'body' => 'Inter' ),
    'layout' => array( 'boxed_width' => 1200, 'section_padding' => 100, 'card_radius' => 16, 'button_radius' => 10,
        'card_shadow' => array( 'horizontal' => 0, 'vertical' => 2, 'blur' => 16, 'spread' => 0, 'color' => 'rgba(0,0,0,0.06)' ) ),
    'sections' => array( 'hero', 'stats', 'features', 'steps', 'testimonials', 'faq', 'cta_final' ),
    'hero' => array(
        'badge' => 'New Year, New You — 50% Off First Month',
        'eyebrow' => 'TRANSFORM YOUR BODY',
        'headline' => 'Get Fit. Get Strong. Get Results.',
        'subheadline' => "Join Austin's top-rated fitness studio with personalized training, group classes, and nutrition coaching that actually works.",
        'cta_primary' => array( 'text' => 'Start Free Trial', 'url' => '#', 'icon' => array( 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ) ),
        'cta_secondary' => array( 'text' => 'View Class Schedule', 'url' => '#schedule' ),
        'trust_line' => 'Rated 4.9/5 by 500+ members',
    ),
    'stats' => array(
        array( 'icon' => array( 'value' => 'fas fa-users', 'library' => 'fa-solid' ), 'value' => '2500+', 'label' => 'Active Members' ),
        array( 'icon' => array( 'value' => 'fas fa-dumbbell', 'library' => 'fa-solid' ), 'value' => '85+', 'label' => 'Classes Per Week' ),
        array( 'icon' => array( 'value' => 'fas fa-trophy', 'library' => 'fa-solid' ), 'value' => '12', 'label' => 'Years of Excellence' ),
        array( 'icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ), 'value' => '4.9', 'label' => 'Google Rating' ),
    ),
    'features' => array(
        'eyebrow' => 'WHY CHOOSE US', 'headline' => 'Everything You Need Under One Roof',
        'items' => array(
            array( 'icon' => array( 'value' => 'fas fa-heartbeat', 'library' => 'fa-solid' ), 'title' => 'Personal Training', 'desc' => 'One-on-one sessions with certified trainers who build custom programs around your goals, schedule, and fitness level.', 'accent' => '#FF6B35' ),
            array( 'icon' => array( 'value' => 'fas fa-users', 'library' => 'fa-solid' ), 'title' => 'Group Classes', 'desc' => 'From HIIT to yoga, spin to boxing — 85+ weekly classes designed to challenge and motivate you.', 'accent' => '#10B981' ),
            array( 'icon' => array( 'value' => 'fas fa-apple-alt', 'library' => 'fa-solid' ), 'title' => 'Nutrition Coaching', 'desc' => 'Work with our dietitians to create sustainable meal plans that fuel your workouts and accelerate results.', 'accent' => '#6366F1' ),
        ),
    ),
    'steps' => array(
        'eyebrow' => 'HOW IT WORKS', 'headline' => 'Your Journey Starts Here', 'anchor' => 'how-it-works',
        'items' => array(
            array( 'num' => '01', 'title' => 'Book Your Free Trial', 'desc' => 'Sign up online and pick a class or training session that fits your schedule.' ),
            array( 'num' => '02', 'title' => 'Meet Your Coach', 'desc' => 'Get a personalized fitness assessment and a roadmap tailored to your goals.' ),
            array( 'num' => '03', 'title' => 'See Real Results', 'desc' => 'Follow your plan, track progress, and watch your body transform week by week.' ),
        ),
    ),
    'testimonials' => array(
        'eyebrow' => 'MEMBER STORIES', 'headline' => 'Real People. Real Results.',
        'items' => array(
            array( 'name' => 'Sarah M.', 'role' => 'Lost 35 lbs in 6 months', 'quote' => "I've tried every gym in Austin. This is the first place where the trainers actually care about your progress. Down 35 pounds and feeling incredible." ),
            array( 'name' => 'David K.', 'role' => 'Marathon finisher', 'quote' => 'The group classes pushed me beyond what I thought was possible. I went from couch potato to finishing my first marathon.' ),
            array( 'name' => 'Lisa R.', 'role' => 'Member for 3 years', 'quote' => 'The nutrition coaching was a game-changer. I finally understand how to eat for my body and my energy levels are through the roof.' ),
        ),
    ),
    'faq' => array(
        'eyebrow' => 'FAQ', 'headline' => "Got Questions? We've Got Answers.",
        'items' => array(
            array( 'q' => 'What does the free trial include?', 'a' => 'Your free trial includes one full week of unlimited group classes, a fitness assessment with a trainer, and a tour of our facility. No commitment required.' ),
            array( 'q' => 'Do I need to be in shape to start?', 'a' => 'Absolutely not. Our programs are designed for all fitness levels. Your coach will modify exercises to match where you are today.' ),
            array( 'q' => 'Can I freeze my membership?', 'a' => 'Yes, you can freeze your membership for up to 3 months per year at no additional cost. Just let our front desk know.' ),
        ),
    ),
    'cta_final' => array(
        'headline' => 'Ready to Transform Your Life?',
        'description' => "Join 2,500+ members who chose to invest in themselves. Your first week is on us.",
        'cta' => array( 'text' => 'Claim Your Free Trial', 'url' => '#', 'icon' => array( 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ) ),
        'trust_line' => 'No credit card required',
    ),
);

// ── Page 2: SaaS PM Tool ──
// Hero: split, Features: alternating with images, Testimonials: featured (large quote), CTA: gradient
$saas_config = array(
    'colors' => array(
        'primary' => '#4F46E5', 'dark_bg' => '#0F172A', 'light_bg' => '#F8FAFC',
        'white' => '#FFFFFF', 'text_dark' => '#0F172A', 'text_muted' => '#64748B',
        'text_light' => 'rgba(255,255,255,0.7)', 'accent' => '#06B6D4', 'gold' => '#F59E0B',
        'border' => 'rgba(0,0,0,0.08)',
    ),
    'fonts' => array( 'heading' => 'Inter', 'body' => 'Inter' ),
    'layout' => array( 'boxed_width' => 1200, 'section_padding' => 100, 'card_radius' => 16, 'button_radius' => 10,
        'card_shadow' => array( 'horizontal' => 0, 'vertical' => 2, 'blur' => 16, 'spread' => 0, 'color' => 'rgba(0,0,0,0.06)' ) ),
    'sections' => array( 'hero', 'social_proof', 'features', 'results', 'competitive_edge', 'testimonials', 'faq', 'cta_final' ),
    'hero' => array(
        'variant' => 'split',
        'badge' => 'Now with AI-powered task prioritization',
        'eyebrow' => 'PROJECT MANAGEMENT REIMAGINED',
        'headline' => 'Ship Faster. Stress Less.',
        'subheadline' => "The project management tool that actually gets out of your way. Built for teams who'd rather ship products than manage spreadsheets.",
        'cta_primary' => array( 'text' => 'Start Free', 'url' => '#', 'icon' => array( 'value' => 'fas fa-rocket', 'library' => 'fa-solid' ) ),
        'cta_secondary' => array( 'text' => 'Watch Demo', 'url' => '#demo' ),
        'trust_line' => 'Trusted by 10,000+ teams worldwide',
        'image' => 'https://images.pexels.com/photos/3183150/pexels-photo-3183150.jpeg?auto=compress&cs=tinysrgb&w=800',
    ),
    'social_proof' => array(
        'headline' => 'Trusted by teams at companies like',
        'categories' => array( 'Startups', 'SaaS', 'Agencies', 'Enterprise', 'E-commerce', 'Fintech', 'Healthcare' ),
    ),
    'features' => array(
        'variant' => 'alternating',
        'eyebrow' => 'FEATURES', 'headline' => 'Tools That Work the Way You Think',
        'subheadline' => 'No 200-page manuals. No week-long onboarding. Just intuitive tools that click from day one.',
        'items' => array(
            array( 'icon' => array( 'value' => 'fas fa-brain', 'library' => 'fa-solid' ), 'title' => 'AI Task Prioritization', 'desc' => 'Our AI analyzes deadlines, dependencies, and team capacity to automatically surface what matters most right now.', 'accent' => '#4F46E5',
                'image' => 'https://images.pexels.com/photos/8386440/pexels-photo-8386440.jpeg?auto=compress&cs=tinysrgb&w=800' ),
            array( 'icon' => array( 'value' => 'fas fa-bolt', 'library' => 'fa-solid' ), 'title' => 'Real-Time Collaboration', 'desc' => 'See changes as they happen. Comment, assign, and update tasks without ever leaving your workflow.', 'accent' => '#06B6D4',
                'image' => 'https://images.pexels.com/photos/3184291/pexels-photo-3184291.jpeg?auto=compress&cs=tinysrgb&w=800' ),
            array( 'icon' => array( 'value' => 'fas fa-chart-line', 'library' => 'fa-solid' ), 'title' => 'Smart Dashboards', 'desc' => "Custom dashboards that show your team's velocity, blockers, and progress — not vanity metrics.", 'accent' => '#10B981',
                'image' => 'https://images.pexels.com/photos/7688336/pexels-photo-7688336.jpeg?auto=compress&cs=tinysrgb&w=800' ),
        ),
    ),
    'results' => array(
        'eyebrow' => 'RESULTS', 'headline' => 'Teams Ship 40% Faster With Us',
        'description' => 'Real numbers from real teams who switched from legacy tools.',
        'metrics' => array(
            array( 'value' => '40%', 'label' => 'Faster delivery', 'color' => '#06B6D4' ),
            array( 'value' => '60%', 'label' => 'Fewer meetings', 'color' => '#10B981' ),
            array( 'value' => '3x', 'label' => 'Better visibility', 'color' => '#F59E0B' ),
            array( 'value' => '95%', 'label' => 'Team adoption', 'color' => '#8B5CF6' ),
        ),
        'cta' => array( 'text' => 'See Case Studies', 'url' => '#' ),
    ),
    'competitive_edge' => array(
        'eyebrow' => 'WHY US', 'headline' => 'Not Just Another PM Tool',
        'description' => "We built this because we were tired of tools that create more work than they eliminate.",
        'cta' => array( 'text' => 'Compare Plans', 'url' => '#pricing' ),
        'benefits' => array(
            'Set up in 5 minutes, not 5 weeks',
            'AI handles task sorting — you handle building',
            'Integrates with GitHub, Slack, Figma, and 50+ tools',
            "Free tier that's actually useful (not a demo)",
            'No per-seat pricing surprises',
            'SOC 2 Type II certified',
        ),
    ),
    'testimonials' => array(
        'variant' => 'featured',
        'eyebrow' => 'TESTIMONIALS', 'headline' => 'Loved by Teams Who Ship',
        'items' => array(
            array( 'name' => 'Alex Chen', 'role' => 'CTO, Stackline', 'quote' => 'We ditched Jira and never looked back. Our sprint velocity went up 35% in the first month. The AI prioritization alone saved our leads 4 hours per week.' ),
            array( 'name' => 'Maria Santos', 'role' => 'Product Lead, Flowbase', 'quote' => "Finally a PM tool that doesn't feel like it was designed by people who've never shipped a product." ),
            array( 'name' => 'James Liu', 'role' => 'Founder, Buildkit', 'quote' => 'The free tier let us try it risk-free. Two weeks later, the whole team had switched voluntarily.' ),
        ),
    ),
    'faq' => array(
        'eyebrow' => 'FAQ', 'headline' => 'Common Questions',
        'items' => array(
            array( 'q' => 'How does the AI prioritization work?', 'a' => 'Our AI analyzes your task deadlines, dependencies, team workload, and historical velocity to rank tasks by urgency and impact.' ),
            array( 'q' => 'Can I import from Jira / Asana / Trello?', 'a' => 'Yes — one-click import from all major PM tools. Most teams are fully migrated in under an hour.' ),
            array( 'q' => "What's included in the free plan?", 'a' => 'Up to 10 users, unlimited projects, basic AI features, and all core integrations. No time limit.' ),
            array( 'q' => 'Is my data secure?', 'a' => "We're SOC 2 Type II certified, encrypt all data at rest and in transit, and offer SSO + SAML on business plans." ),
        ),
    ),
    'cta_final' => array(
        'headline' => 'Start Shipping Faster Today',
        'description' => "Join 10,000+ teams who upgraded their workflow. Free forever for small teams.",
        'cta' => array( 'text' => 'Get Started Free', 'url' => '#', 'icon' => array( 'value' => 'fas fa-rocket', 'library' => 'fa-solid' ) ),
        'trust_line' => 'No credit card required',
    ),
);

// ── Page 3: Restaurant ──
// Hero: background image, Features: card grid, Testimonials: default 3-card, CTA: card (new variant!)
$restaurant_config = array(
    'colors' => array(
        'primary' => '#B91C1C', 'dark_bg' => '#1C1917', 'light_bg' => '#FFFBEB',
        'white' => '#FFFFFF', 'text_dark' => '#1C1917', 'text_muted' => '#78716C',
        'text_light' => 'rgba(255,255,255,0.7)', 'accent' => '#D97706', 'gold' => '#F59E0B',
        'border' => 'rgba(0,0,0,0.08)',
    ),
    'fonts' => array( 'heading' => 'Playfair Display', 'body' => 'Lato' ),
    'layout' => array( 'boxed_width' => 1200, 'section_padding' => 100, 'card_radius' => 12, 'button_radius' => 8,
        'card_shadow' => array( 'horizontal' => 0, 'vertical' => 2, 'blur' => 16, 'spread' => 0, 'color' => 'rgba(0,0,0,0.06)' ) ),
    'sections' => array( 'hero', 'features', 'steps', 'testimonials', 'faq', 'cta_final' ),
    'hero' => array(
        'variant' => 'image',
        'eyebrow' => 'EST. 2012 · AUSTIN, TEXAS',
        'headline' => 'Farm-to-Table Italian. No Compromises.',
        'subheadline' => "Hand-made pasta, locally sourced ingredients, and a wine list that'll make you forget you're in Texas.",
        'cta_primary' => array( 'text' => 'Reserve a Table', 'url' => '#', 'icon' => array( 'value' => 'fas fa-utensils', 'library' => 'fa-solid' ) ),
        'cta_secondary' => array( 'text' => 'View Menu', 'url' => '#menu' ),
        'trust_line' => 'Austin Chronicle Best Italian 2024 & 2025',
        'image' => 'https://images.pexels.com/photos/1279330/pexels-photo-1279330.jpeg?auto=compress&cs=tinysrgb&w=800',
    ),
    'features' => array(
        'eyebrow' => 'THE EXPERIENCE', 'headline' => 'What Makes Us Different',
        'items' => array(
            array( 'icon' => array( 'value' => 'fas fa-leaf', 'library' => 'fa-solid' ), 'title' => 'Locally Sourced', 'desc' => '90% of our ingredients come from farms within 100 miles. We know our farmers by name and visit them monthly.', 'accent' => '#059669' ),
            array( 'icon' => array( 'value' => 'fas fa-hand-sparkles', 'library' => 'fa-solid' ), 'title' => 'Made Fresh Daily', 'desc' => 'Every pasta, sauce, and bread is made in-house each morning. No freezers, no shortcuts, no exceptions.', 'accent' => '#B91C1C' ),
            array( 'icon' => array( 'value' => 'fas fa-wine-glass-alt', 'library' => 'fa-solid' ), 'title' => '200+ Italian Wines', 'desc' => 'Our sommelier curates a rotating selection from every region of Italy, with 30 options by the glass.', 'accent' => '#7C3AED' ),
        ),
    ),
    'steps' => array(
        'eyebrow' => 'DINING WITH US', 'headline' => 'Your Evening, Perfected', 'anchor' => 'how-it-works',
        'items' => array(
            array( 'num' => '01', 'title' => 'Reserve Online', 'desc' => 'Book your table in seconds. Let us know about dietary needs or special occasions.' ),
            array( 'num' => '02', 'title' => 'Arrive & Unwind', 'desc' => 'Start with a craft cocktail at our bar while we prepare your table.' ),
            array( 'num' => '03', 'title' => 'Savor Every Bite', 'desc' => "Let our staff guide you through the menu or go off-script. Either way, you're in for something special." ),
        ),
    ),
    'testimonials' => array(
        'eyebrow' => 'REVIEWS', 'headline' => 'What Our Guests Say',
        'items' => array(
            array( 'name' => 'Robert T.', 'role' => 'Google Review', 'quote' => "Best Italian food I've had outside of Italy. The hand-made pappardelle with wild boar ragu is life-changing." ),
            array( 'name' => 'Emily W.', 'role' => 'Yelp Elite', 'quote' => 'We held our rehearsal dinner here and it was perfect. The staff went above and beyond.' ),
            array( 'name' => 'Marcus J.', 'role' => 'Austin Food Blog', 'quote' => "In a city full of great restaurants, this place stands out. The commitment to local sourcing isn't just marketing." ),
        ),
    ),
    'faq' => array(
        'eyebrow' => 'FAQ', 'headline' => 'Before You Visit',
        'items' => array(
            array( 'q' => 'Do you accommodate dietary restrictions?', 'a' => 'We offer gluten-free pasta, dairy-free options, and can accommodate most allergies.' ),
            array( 'q' => 'Is there a dress code?', 'a' => 'Smart casual. Think date night attire.' ),
            array( 'q' => 'Do you offer private dining?', 'a' => 'Yes — our private room seats up to 24 guests with a dedicated server and custom menu.' ),
            array( 'q' => 'What are your hours?', 'a' => 'Tuesday–Thursday 5pm–10pm, Friday–Saturday 5pm–11pm, Sunday 4pm–9pm. Closed Mondays.' ),
        ),
    ),
    'cta_final' => array(
        'variant' => 'card',
        'headline' => 'Your Table Awaits',
        'description' => "Experience Austin's finest Italian dining. Walk-ins welcome, but reservations are recommended.",
        'cta' => array( 'text' => 'Make a Reservation', 'url' => '#', 'icon' => array( 'value' => 'fas fa-calendar-check', 'library' => 'fa-solid' ) ),
        'trust_line' => "OpenTable 2025 Diners' Choice Award",
    ),
);

// ── Page 4: ReviewBoost ──
// Hero: split, Features: card grid, Testimonials: featured, CTA: card, Competitive Edge: image variant
$reviewboost_config = array(
    'colors' => array(
        'primary' => '#046BD2', 'dark_bg' => '#0B1D33', 'light_bg' => '#F0F7FF',
        'white' => '#FFFFFF', 'text_dark' => '#0B1D33', 'text_muted' => '#5B6B7D',
        'text_light' => 'rgba(255,255,255,0.7)', 'accent' => '#00B418', 'gold' => '#F59E0B',
        'border' => 'rgba(0,0,0,0.08)',
    ),
    'fonts' => array( 'heading' => 'Inter', 'body' => 'Inter' ),
    'layout' => array( 'boxed_width' => 1200, 'section_padding' => 100, 'card_radius' => 12, 'button_radius' => 8,
        'card_shadow' => array( 'horizontal' => 0, 'vertical' => 2, 'blur' => 16, 'spread' => 0, 'color' => 'rgba(0,0,0,0.06)' ) ),
    'sections' => array( 'hero', 'stats', 'social_proof', 'features', 'steps', 'results', 'competitive_edge', 'testimonials', 'faq', 'cta_final' ),
    'hero' => array(
        'variant' => 'split',
        'badge' => 'Trusted by 2,000+ local businesses',
        'eyebrow' => 'ONLINE REPUTATION MANAGEMENT',
        'headline' => 'Get More Google Reviews. Grow Your Business.',
        'subheadline' => 'The simplest way to collect, manage, and showcase customer reviews. Trusted by restaurants, clinics, salons, and service businesses.',
        'cta_primary' => array( 'text' => 'Start Free Trial', 'url' => '#', 'icon' => array( 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ) ),
        'cta_secondary' => array( 'text' => 'See How It Works', 'url' => '#how-it-works' ),
        'trust_line' => 'No credit card required · Setup in under 5 minutes',
        'image' => 'https://images.pexels.com/photos/3184465/pexels-photo-3184465.jpeg?auto=compress&cs=tinysrgb&w=800',
    ),
    'stats' => array(
        'variant' => 'dark',
        'items' => array(
            array( 'icon' => array( 'value' => 'fas fa-star', 'library' => 'fa-solid' ), 'value' => '3x', 'label' => 'More Reviews' ),
            array( 'icon' => array( 'value' => 'fas fa-chart-line', 'library' => 'fa-solid' ), 'value' => '47%', 'label' => 'Revenue Increase' ),
            array( 'icon' => array( 'value' => 'fas fa-building', 'library' => 'fa-solid' ), 'value' => '500+', 'label' => 'Businesses Served' ),
            array( 'icon' => array( 'value' => 'fas fa-shield-alt', 'library' => 'fa-solid' ), 'value' => '98%', 'label' => 'Retention Rate' ),
        ),
    ),
    'social_proof' => array(
        'headline' => 'Trusted by businesses across industries',
        'categories' => array( 'Restaurants', 'Dentists', 'Salons', 'Auto Repair', 'Lawyers', 'Contractors', 'Realtors', 'Veterinarians' ),
    ),
    'features' => array(
        'eyebrow' => 'PLATFORM', 'headline' => 'Everything You Need to Win at Reviews',
        'subheadline' => 'From automated requests to smart routing, we handle the complexity so you can focus on your customers.',
        'items' => array(
            array( 'icon' => array( 'value' => 'fas fa-paper-plane', 'library' => 'fa-solid' ), 'title' => 'Automated Review Requests', 'desc' => 'Send SMS and email review requests automatically after each visit. Smart timing gets 3x more responses.', 'accent' => '#046BD2' ),
            array( 'icon' => array( 'value' => 'fas fa-route', 'library' => 'fa-solid' ), 'title' => 'Smart Review Routing', 'desc' => 'Happy customers go to Google. Unhappy ones come to you first. Protect your reputation automatically.', 'accent' => '#00B418' ),
            array( 'icon' => array( 'value' => 'fas fa-chart-bar', 'library' => 'fa-solid' ), 'title' => 'Analytics Dashboard', 'desc' => 'Track review volume, sentiment trends, and response rates. See which locations and staff drive the best reviews.', 'accent' => '#8B5CF6' ),
        ),
    ),
    'steps' => array(
        'variant' => 'compact',
        'eyebrow' => 'HOW IT WORKS', 'headline' => 'Three Steps to More Reviews', 'anchor' => 'how-it-works',
        'items' => array(
            array( 'num' => '01', 'title' => 'Connect Your Business', 'desc' => 'Link your Google Business Profile in 30 seconds. We handle the rest.' ),
            array( 'num' => '02', 'title' => 'Customers Get Invited', 'desc' => 'After each visit, customers receive a friendly review request via SMS or email.' ),
            array( 'num' => '03', 'title' => 'Watch Reviews Grow', 'desc' => 'Sit back as your review count and star rating climb. Monitor everything from your dashboard.' ),
        ),
    ),
    'results' => array(
        'eyebrow' => 'PROVEN RESULTS', 'headline' => 'Real Results From Real Businesses',
        'description' => 'Average improvements our customers see in the first 90 days.',
        'metrics' => array(
            array( 'value' => '3x', 'label' => 'More monthly reviews', 'color' => '#046BD2' ),
            array( 'value' => '4.7', 'label' => 'Average star rating', 'color' => '#F59E0B' ),
            array( 'value' => '89%', 'label' => 'Open rate on requests', 'color' => '#00B418' ),
            array( 'value' => '47%', 'label' => 'Revenue increase', 'color' => '#8B5CF6' ),
        ),
        'cta' => array( 'text' => 'Read Customer Stories', 'url' => '#' ),
    ),
    'competitive_edge' => array(
        'variant' => 'image',
        'eyebrow' => 'WHY REVIEWBOOST', 'headline' => 'Why Businesses Switch to ReviewBoost',
        'description' => "Other tools make review management complicated. We made it effortless.",
        'cta' => array( 'text' => 'Start Free Trial', 'url' => '#', 'icon' => array( 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ) ),
        'benefits' => array(
            'Setup in under 5 minutes — no technical skills needed',
            '3x more reviews than manual requests',
            'Smart routing protects your rating from negative reviews',
            'Works with Google, Yelp, Facebook, and 20+ platforms',
            'Dedicated support team responds in under 2 hours',
            'Cancel anytime — no contracts, no tricks',
        ),
        'image' => 'https://images.pexels.com/photos/3184306/pexels-photo-3184306.jpeg?auto=compress&cs=tinysrgb&w=800',
    ),
    'testimonials' => array(
        'variant' => 'featured',
        'eyebrow' => 'CUSTOMER STORIES', 'headline' => 'Loved by Local Businesses',
        'items' => array(
            array( 'name' => 'Dr. Sarah Kim', 'role' => 'Kim Family Dentistry', 'quote' => "We went from 12 Google reviews to over 200 in six months. New patients specifically mention our reviews when they book. ReviewBoost paid for itself in the first week." ),
            array( 'name' => 'Marco Rossi', 'role' => "Marco's Italian Kitchen", 'quote' => "The smart routing saved us. A few upset customers got redirected to us privately instead of blasting us on Google." ),
            array( 'name' => 'Jennifer Walsh', 'role' => 'Walsh & Associates Law', 'quote' => "We're a law firm — we can't exactly ask clients for reviews in person. The automated SMS requests are tasteful and effective." ),
        ),
    ),
    'faq' => array(
        'eyebrow' => 'FAQ', 'headline' => 'Frequently Asked Questions',
        'items' => array(
            array( 'q' => 'Is it against Google guidelines?', 'a' => "No. Google encourages businesses to ask customers for reviews. We simply automate the asking process — we never incentivize or fake reviews." ),
            array( 'q' => 'How quickly will I see results?', 'a' => 'Most businesses see a noticeable increase in reviews within the first 2 weeks. Full results typically emerge within 60-90 days.' ),
            array( 'q' => 'Can I try it for free?', 'a' => "Yes! Our 14-day free trial includes all features. No credit card required. You'll see results before you ever pay." ),
            array( 'q' => 'What if I get a negative review?', 'a' => "Our smart routing catches unhappy customers before they post publicly. You'll get alerted privately so you can resolve the issue first." ),
        ),
    ),
    'cta_final' => array(
        'variant' => 'card',
        'headline' => 'Ready to Get More Reviews?',
        'description' => "Join 500+ businesses that trust ReviewBoost to grow their online reputation. Start your free trial today.",
        'cta' => array( 'text' => 'Start Free — No Card Required', 'url' => '#', 'icon' => array( 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ) ),
        'trust_line' => '14-day free trial · No credit card · Cancel anytime',
    ),
);

// ── Generate all 4 pages ──
$configs = array(
    array( 'title' => 'Fitness Studio — PressGo Test', 'slug' => 'fitness-studio-pressgo-test', 'config' => $fitness_config ),
    array( 'title' => 'ShipFast PM Tool — PressGo Test', 'slug' => 'shipfast-pm-tool-pressgo-test', 'config' => $saas_config ),
    array( 'title' => 'Trattoria Roma — PressGo Test', 'slug' => 'trattoria-roma-pressgo-test', 'config' => $restaurant_config ),
    array( 'title' => 'ReviewBoost — PressGo Test', 'slug' => 'reviewboost-pressgo-test', 'config' => $reviewboost_config ),
);

$generator = new PressGo_Generator();
$creator   = new PressGo_Page_Creator();

foreach ( $configs as $page ) {
    $config = PressGo_Config_Validator::validate( $page['config'] );
    if ( is_wp_error( $config ) ) {
        WP_CLI::error( "Validation failed for '{$page['title']}': " . $config->get_error_message() );
        continue;
    }
    $elements = $generator->generate( $config );
    $post_id  = $creator->create_page( $page['title'], $elements, $config );
    if ( is_wp_error( $post_id ) ) {
        WP_CLI::error( "Failed to create '{$page['title']}': " . $post_id->get_error_message() );
    } else {
        // Publish with proper slug.
        wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'post_name' => $page['slug'] ) );
        WP_CLI::success( "Created '{$page['title']}' → Post ID: {$post_id} → /{$page['slug']}/" );
    }
}
