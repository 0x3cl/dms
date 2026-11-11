/**
 * Who this deployment belongs to, and how to reach them.
 *
 * PROCMIS keeps the equivalent strings in a database-backed Pages module so a
 * superadmin can edit the header and footer without a deploy. DocTrack serves
 * one office, so the copy lives here instead — one file to change, no table,
 * no upload pipeline. If this app ever serves more than one office, that is
 * the point to reconsider.
 */
export const organization = {
    logo: '/ptri.png',
    logoAlt: 'DOST-PTRI',
    line1: 'Republic of the Philippines',
    line2: 'Department of Science and Technology',
    line3: 'Philippine Textile Research Institute',
    label: 'Document Management System',
    title: 'DocTrack',

    orgName: 'DOST - Philippine Textile Research Institute',
    address: 'Gen. Santos Ave., Bicutan, Taguig City, Metro Manila 1631',
    phoneLabel: '(02) 8837-1325',
    phoneHref: '+6328371325',
    email: 'ptri@ptri.dost.gov.ph',
    websiteLabel: 'ptri.dost.gov.ph',
    websiteUrl: 'https://ptri.dost.gov.ph',
    tagline: 'Every document tracked, from upload to hand-off.',
};

export default organization;
