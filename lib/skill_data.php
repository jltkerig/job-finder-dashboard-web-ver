<?php
// Skill names and the words that mean them, skills that go together, and related job titles: copied from the
// desktop Job Finder (profile_tools.py and profile_store.py) so both versions recognize the same skills.

declare(strict_types=1);

const SKILL_ALIASES = array (
  'HTML' => 
  array (
    0 => 'html',
    1 => 'html5',
  ),
  'CSS' => 
  array (
    0 => 'css',
    1 => 'css3',
  ),
  'Sass' => 
  array (
    0 => 'sass',
    1 => 'scss',
  ),
  'JavaScript' => 
  array (
    0 => 'javascript',
    1 => 'ecmascript',
  ),
  'TypeScript' => 
  array (
    0 => 'typescript',
  ),
  'React' => 
  array (
    0 => 'react',
    1 => 'reactjs',
    2 => 'react.js',
  ),
  'Vue' => 
  array (
    0 => 'vue',
    1 => 'vue.js',
  ),
  'Angular' => 
  array (
    0 => 'angular',
  ),
  'Python' => 
  array (
    0 => 'python',
  ),
  'PHP' => 
  array (
    0 => 'php',
  ),
  'SQL' => 
  array (
    0 => 'sql',
  ),
  'MySQL' => 
  array (
    0 => 'mysql',
  ),
  'Node.js' => 
  array (
    0 => 'node.js',
    1 => 'nodejs',
  ),
  'WordPress' => 
  array (
    0 => 'wordpress',
  ),
  'Drupal' => 
  array (
    0 => 'drupal',
  ),
  'Bootstrap' => 
  array (
    0 => 'bootstrap',
  ),
  'Git' => 
  array (
    0 => 'git',
    1 => 'github',
    2 => 'gitlab',
  ),
  'Docker' => 
  array (
    0 => 'docker',
  ),
  'Figma' => 
  array (
    0 => 'figma',
  ),
  'Adobe Photoshop' => 
  array (
    0 => 'photoshop',
  ),
  'Adobe Illustrator' => 
  array (
    0 => 'illustrator',
  ),
  'Adobe InDesign' => 
  array (
    0 => 'indesign',
  ),
  'Adobe Creative Cloud' => 
  array (
    0 => 'adobe creative cloud',
    1 => 'adobe creative suite',
  ),
  'Responsive Design' => 
  array (
    0 => 'responsive design',
    1 => 'responsive websites',
    2 => 'responsive web design',
  ),
  'Accessibility' => 
  array (
    0 => 'accessibility',
    1 => 'wcag',
    2 => 'a11y',
  ),
  'SEO' => 
  array (
    0 => 'seo',
    1 => 'search engine optimization',
  ),
  'Google Analytics' => 
  array (
    0 => 'google analytics',
    1 => 'ga4',
  ),
  'Google Tag Manager' => 
  array (
    0 => 'google tag manager',
    1 => 'gtm',
  ),
  'Hotjar' => 
  array (
    0 => 'hotjar',
  ),
  'BrowserStack' => 
  array (
    0 => 'browserstack',
  ),
  'Litmus' => 
  array (
    0 => 'litmus',
  ),
  'Salesforce Marketing Cloud' => 
  array (
    0 => 'salesforce marketing cloud',
    1 => 'exacttarget',
  ),
  'Amazon S3' => 
  array (
    0 => 'amazon s3',
    1 => 'aws s3',
  ),
  'Jira' => 
  array (
    0 => 'jira',
  ),
  'Confluence' => 
  array (
    0 => 'confluence',
  ),
  'Notion' => 
  array (
    0 => 'notion',
  ),
  'Trello' => 
  array (
    0 => 'trello',
  ),
  'monday.com' => 
  array (
    0 => 'monday.com',
  ),
  'jQuery' => 
  array (
    0 => 'jquery',
  ),
  'REST APIs' => 
  array (
    0 => 'rest api',
    1 => 'restful api',
  ),
  'Email Marketing' => 
  array (
    0 => 'email marketing',
    1 => 'email campaigns',
  ),
  'Content Management' => 
  array (
    0 => 'content management',
    1 => 'cms',
  ),
  'UI Design' => 
  array (
    0 => 'ui design',
    1 => 'user interface design',
  ),
  'UX Design' => 
  array (
    0 => 'ux design',
    1 => 'user experience design',
  ),
  'Project Management' => 
  array (
    0 => 'project management',
    1 => 'project-managed',
  ),
  'Canva' => 
  array (
    0 => 'canva',
  ),
  'Adobe After Effects' => 
  array (
    0 => 'adobe after effects',
    1 => 'after effects',
  ),
  'Microsoft Excel' => 
  array (
    0 => 'microsoft excel',
    1 => 'excel spreadsheets',
  ),
  'Web Design' => 
  array (
    0 => 'web design',
    1 => 'website design',
    2 => 'web designer',
  ),
  'Web Development' => 
  array (
    0 => 'web development',
    1 => 'web developer',
    2 => 'front end development',
    3 => 'frontend development',
    4 => 'front-end development',
    5 => 'front end developer',
    6 => 'frontend developer',
    7 => 'front-end developer',
  ),
  'Graphic Design' => 
  array (
    0 => 'graphic design',
    1 => 'graphic designer',
  ),
  'Visual Design' => 
  array (
    0 => 'visual design',
  ),
  'Landing Pages' => 
  array (
    0 => 'landing page',
    1 => 'landing pages',
  ),
  'A/B Testing' => 
  array (
    0 => 'a/b testing',
    1 => 'a/b test',
    2 => 'ab testing',
    3 => 'split testing',
  ),
  'SMS Marketing' => 
  array (
    0 => 'sms marketing',
    1 => 'text message marketing',
  ),
  'Digital Marketing' => 
  array (
    0 => 'digital marketing',
  ),
  'Marketing Automation' => 
  array (
    0 => 'marketing automation',
  ),
  'Social Media' => 
  array (
    0 => 'social media',
  ),
  'Copywriting' => 
  array (
    0 => 'copywriting',
  ),
  'Content Strategy' => 
  array (
    0 => 'content strategy',
  ),
  'Branding' => 
  array (
    0 => 'branding',
    1 => 'brand identity',
  ),
  'Typography' => 
  array (
    0 => 'typography',
  ),
  'Print Design' => 
  array (
    0 => 'print design',
    1 => 'print production',
    2 => 'prepress',
    3 => 'pre-press',
  ),
  'Wireframing' => 
  array (
    0 => 'wireframe',
    1 => 'wireframes',
    2 => 'wireframing',
  ),
  'Prototyping' => 
  array (
    0 => 'prototype',
    1 => 'prototypes',
    2 => 'prototyping',
  ),
  'Photo Editing' => 
  array (
    0 => 'photo editing',
    1 => 'image editing',
    2 => 'photo manipulation',
    3 => 'photo retouching',
  ),
  'Photography' => 
  array (
    0 => 'photography',
  ),
  'Video Editing' => 
  array (
    0 => 'video editing',
    1 => 'video production',
  ),
  'Adobe Premiere Pro' => 
  array (
    0 => 'premiere pro',
    1 => 'adobe premiere',
  ),
  'Adobe XD' => 
  array (
    0 => 'adobe xd',
  ),
  'Adobe Lightroom' => 
  array (
    0 => 'lightroom',
  ),
  'Adobe Acrobat' => 
  array (
    0 => 'adobe acrobat',
  ),
  'Microsoft Word' => 
  array (
    0 => 'microsoft word',
    1 => 'ms word',
  ),
  'Microsoft PowerPoint' => 
  array (
    0 => 'powerpoint',
  ),
  'Microsoft Office' => 
  array (
    0 => 'microsoft office',
    1 => 'ms office',
    2 => 'office 365',
    3 => 'microsoft 365',
  ),
  'Webflow' => 
  array (
    0 => 'webflow',
  ),
  'Squarespace' => 
  array (
    0 => 'squarespace',
  ),
  'Wix' => 
  array (
    0 => 'wix',
  ),
  'Shopify' => 
  array (
    0 => 'shopify',
  ),
  'WooCommerce' => 
  array (
    0 => 'woocommerce',
  ),
  'HubSpot' => 
  array (
    0 => 'hubspot',
  ),
  'Mailchimp' => 
  array (
    0 => 'mailchimp',
  ),
  'Marketo' => 
  array (
    0 => 'marketo',
  ),
  'Klaviyo' => 
  array (
    0 => 'klaviyo',
  ),
  'Constant Contact' => 
  array (
    0 => 'constant contact',
  ),
  'Google Ads' => 
  array (
    0 => 'google ads',
    1 => 'adwords',
  ),
  'CRM' => 
  array (
    0 => 'crm',
  ),
  'Cross-Browser Testing' => 
  array (
    0 => 'cross-browser',
    1 => 'cross browser',
  ),
  'QA Testing' => 
  array (
    0 => 'qa testing',
    1 => 'quality assurance',
  ),
  'Agile' => 
  array (
    0 => 'agile',
    1 => 'scrum',
  ),
  'Tailwind CSS' => 
  array (
    0 => 'tailwind',
    1 => 'tailwind css',
  ),
  'Webpack' => 
  array (
    0 => 'webpack',
  ),
  'Next.js' => 
  array (
    0 => 'next.js',
    1 => 'nextjs',
  ),
  'GraphQL' => 
  array (
    0 => 'graphql',
  ),
  'JSON' => 
  array (
    0 => 'json',
  ),
  'AWS' => 
  array (
    0 => 'aws',
    1 => 'amazon web services',
  ),
  'DNS' => 
  array (
    0 => 'dns',
  ),
  'VS Code' => 
  array (
    0 => 'vs code',
    1 => 'visual studio code',
  ),
  'Data Analysis' => 
  array (
    0 => 'data analysis',
    1 => 'data analytics',
  ),
);

// Skills that are also everyday words ("react quickly", "a notion of", "bootstrap the brand").
const AMBIGUOUS_SKILLS = array (
  0 => 'Angular',
  1 => 'Bootstrap',
  2 => 'Confluence',
  3 => 'Litmus',
  4 => 'Notion',
  5 => 'React',
);

// Skills that usually go together; the jobs found add to these (see related_skills).
const RELATED_SKILLS = array (
  'HTML' => 
  array (
    0 => 'CSS',
    1 => 'JavaScript',
    2 => 'Responsive Design',
    3 => 'Accessibility',
    4 => 'SEO',
  ),
  'CSS' => 
  array (
    0 => 'HTML',
    1 => 'Sass',
    2 => 'Bootstrap',
    3 => 'Responsive Design',
    4 => 'JavaScript',
  ),
  'JavaScript' => 
  array (
    0 => 'TypeScript',
    1 => 'React',
    2 => 'jQuery',
    3 => 'HTML',
    4 => 'CSS',
    5 => 'Node.js',
  ),
  'TypeScript' => 
  array (
    0 => 'JavaScript',
    1 => 'React',
    2 => 'Angular',
    3 => 'Node.js',
  ),
  'React' => 
  array (
    0 => 'JavaScript',
    1 => 'TypeScript',
    2 => 'Redux',
    3 => 'HTML',
    4 => 'CSS',
  ),
  'jQuery' => 
  array (
    0 => 'JavaScript',
    1 => 'HTML',
    2 => 'CSS',
    3 => 'Bootstrap',
  ),
  'Bootstrap' => 
  array (
    0 => 'HTML',
    1 => 'CSS',
    2 => 'Responsive Design',
    3 => 'Sass',
  ),
  'Sass' => 
  array (
    0 => 'CSS',
    1 => 'HTML',
    2 => 'Bootstrap',
  ),
  'PHP' => 
  array (
    0 => 'MySQL',
    1 => 'WordPress',
    2 => 'JavaScript',
    3 => 'HTML',
  ),
  'WordPress' => 
  array (
    0 => 'PHP',
    1 => 'HTML',
    2 => 'CSS',
    3 => 'SEO',
    4 => 'Content Management',
  ),
  'SQL' => 
  array (
    0 => 'MySQL',
    1 => 'Python',
    2 => 'Data Analysis',
  ),
  'MySQL' => 
  array (
    0 => 'SQL',
    1 => 'PHP',
    2 => 'Python',
  ),
  'Python' => 
  array (
    0 => 'SQL',
    1 => 'Flask',
    2 => 'Data Analysis',
    3 => 'Git',
  ),
  'Git' => 
  array (
    0 => 'GitHub',
    1 => 'Agile',
    2 => 'JavaScript',
  ),
  'Responsive Design' => 
  array (
    0 => 'HTML',
    1 => 'CSS',
    2 => 'Bootstrap',
    3 => 'UX Design',
    4 => 'Accessibility',
  ),
  'Accessibility' => 
  array (
    0 => 'HTML',
    1 => 'CSS',
    2 => 'UX Design',
    3 => 'Responsive Design',
  ),
  'SEO' => 
  array (
    0 => 'Google Analytics',
    1 => 'Content Management',
    2 => 'HTML',
    3 => 'WordPress',
  ),
  'Web Design' => 
  array (
    0 => 'UI Design',
    1 => 'UX Design',
    2 => 'Figma',
    3 => 'Adobe Photoshop',
    4 => 'HTML',
    5 => 'CSS',
  ),
  'UI Design' => 
  array (
    0 => 'UX Design',
    1 => 'Figma',
    2 => 'Web Design',
    3 => 'Adobe XD',
  ),
  'UX Design' => 
  array (
    0 => 'UI Design',
    1 => 'Figma',
    2 => 'Wireframing',
    3 => 'User Research',
    4 => 'Accessibility',
  ),
  'Figma' => 
  array (
    0 => 'UI Design',
    1 => 'UX Design',
    2 => 'Adobe XD',
    3 => 'Sketch',
    4 => 'Prototyping',
  ),
  'Graphic Design' => 
  array (
    0 => 'Adobe Photoshop',
    1 => 'Adobe Illustrator',
    2 => 'Adobe InDesign',
    3 => 'Branding',
    4 => 'Typography',
  ),
  'Adobe Photoshop' => 
  array (
    0 => 'Adobe Illustrator',
    1 => 'Adobe InDesign',
    2 => 'Graphic Design',
    3 => 'Adobe Creative Cloud',
  ),
  'Adobe Illustrator' => 
  array (
    0 => 'Adobe Photoshop',
    1 => 'Adobe InDesign',
    2 => 'Graphic Design',
    3 => 'Typography',
  ),
  'Adobe InDesign' => 
  array (
    0 => 'Adobe Illustrator',
    1 => 'Adobe Photoshop',
    2 => 'Graphic Design',
    3 => 'Typography',
  ),
  'Email Marketing' => 
  array (
    0 => 'Salesforce Marketing Cloud',
    1 => 'HTML',
    2 => 'CSS',
    3 => 'A/B Testing',
    4 => 'Litmus',
  ),
  'Salesforce Marketing Cloud' => 
  array (
    0 => 'Email Marketing',
    1 => 'HTML',
    2 => 'SQL',
    3 => 'A/B Testing',
  ),
  'A/B Testing' => 
  array (
    0 => 'Google Analytics',
    1 => 'Email Marketing',
    2 => 'Data Analysis',
  ),
  'Google Analytics' => 
  array (
    0 => 'SEO',
    1 => 'Google Tag Manager',
    2 => 'A/B Testing',
    3 => 'Data Analysis',
  ),
  'Google Tag Manager' => 
  array (
    0 => 'Google Analytics',
    1 => 'JavaScript',
    2 => 'SEO',
  ),
  'Content Management' => 
  array (
    0 => 'WordPress',
    1 => 'SEO',
    2 => 'HTML',
    3 => 'Copywriting',
  ),
  'Landing Pages' => 
  array (
    0 => 'HTML',
    1 => 'CSS',
    2 => 'A/B Testing',
    3 => 'Email Marketing',
    4 => 'Responsive Design',
  ),
  'Agile' => 
  array (
    0 => 'Scrum',
    1 => 'Jira',
    2 => 'Git',
  ),
  'Docker' => 
  array (
    0 => 'Git',
    1 => 'AWS',
    2 => 'Linux',
  ),
);

const RELATED_JOB_TITLES = array (
  'web designer' => 
  array (
    0 => 'UI Designer',
    1 => 'UX/UI Designer',
    2 => 'Digital Designer',
    3 => 'Website Designer',
    4 => 'Visual Designer',
    5 => 'WordPress Designer',
  ),
  'front end developer' => 
  array (
    0 => 'Frontend Developer',
    1 => 'Web Developer',
    2 => 'UI Developer',
    3 => 'Junior Web Developer',
    4 => 'WordPress Developer',
    5 => 'Web Content Developer',
  ),
  'frontend developer' => 
  array (
    0 => 'Front End Developer',
    1 => 'Web Developer',
    2 => 'UI Developer',
    3 => 'Junior Web Developer',
    4 => 'WordPress Developer',
    5 => 'Web Content Developer',
  ),
  'web developer' => 
  array (
    0 => 'Front End Developer',
    1 => 'Frontend Developer',
    2 => 'Junior Web Developer',
    3 => 'WordPress Developer',
    4 => 'UI Developer',
    5 => 'Web Application Developer',
  ),
  'wordpress developer' => 
  array (
    0 => 'Web Developer',
    1 => 'Front End Developer',
    2 => 'WordPress Designer',
    3 => 'Website Developer',
    4 => 'PHP Developer',
    5 => 'Web Content Developer',
  ),
  'ui designer' => 
  array (
    0 => 'Web Designer',
    1 => 'UX/UI Designer',
    2 => 'Visual Designer',
    3 => 'Product Designer',
    4 => 'Digital Designer',
    5 => 'Interaction Designer',
  ),
  'ux designer' => 
  array (
    0 => 'UX/UI Designer',
    1 => 'UI Designer',
    2 => 'Product Designer',
    3 => 'Interaction Designer',
    4 => 'Web Designer',
    5 => 'Experience Designer',
  ),
  'ux/ui designer' => 
  array (
    0 => 'UI Designer',
    1 => 'UX Designer',
    2 => 'Product Designer',
    3 => 'Web Designer',
    4 => 'Interaction Designer',
    5 => 'Digital Designer',
  ),
  'graphic designer' => 
  array (
    0 => 'Digital Designer',
    1 => 'Visual Designer',
    2 => 'Web Designer',
    3 => 'Production Designer',
    4 => 'Marketing Designer',
    5 => 'Brand Designer',
  ),
  'website content coordinator' => 
  array (
    0 => 'Web Content Coordinator',
    1 => 'Web Content Specialist',
    2 => 'Content Coordinator',
    3 => 'CMS Specialist',
    4 => 'Digital Content Specialist',
    5 => 'Website Coordinator',
  ),
  'web content coordinator' => 
  array (
    0 => 'Website Content Coordinator',
    1 => 'Web Content Specialist',
    2 => 'Content Coordinator',
    3 => 'CMS Specialist',
    4 => 'Digital Content Specialist',
    5 => 'Website Coordinator',
  ),
  'content coordinator' => 
  array (
    0 => 'Web Content Coordinator',
    1 => 'Website Content Coordinator',
    2 => 'Content Specialist',
    3 => 'Digital Content Specialist',
    4 => 'CMS Specialist',
    5 => 'Marketing Coordinator',
  ),
);
