const { test, expect } = require('@playwright/test');

test('Student admission submits exactly the email of an existing Guardian selected in Select2', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill('qa_admin@bowen-qa.test');
    await page.locator('input[name="password"]').fill('local-bowen-qa-only');
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
    await page.waitForURL(/dashboard/);

    const suffix = Date.now();
    const email = `admission-guardian-${suffix}@bowen-qa.test`;

    await page.goto('/guardian/create', { waitUntil: 'domcontentloaded' });
    await page.locator('#guardian_first_name').fill('Admission');
    await page.locator('#guardian_last_name').fill(`Guardian ${suffix}`);
    await page.locator('#guardian_email').fill(email);
    await page.locator('#guardian_mobile').fill(`091${String(suffix).slice(-7)}`);
    await page.locator('#guardian_male').check();
    await page.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByText('Data Created Successfully')).toBeVisible();

    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });

    await expect(page.locator('#guardian_admission_guardian_id')).toBeVisible();
    await expect(page.locator('input[name="guardian_email"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/assets/js/custom/custom.js"]')).toHaveAttribute('src', /\?v=[a-f0-9]{64}$/);

    await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    const matchingGuardian = page.locator('.select2-results__option').filter({ hasText: email }).last();
    await expect(matchingGuardian).toBeVisible();
    await matchingGuardian.click();

    await expect(page.locator('#guardian_admission_guardian_id option:checked')).toHaveText(email);
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);
    const selectedGuardian = await page.locator('#guardian_admission_guardian_id').evaluate((select) => $(select).select2('data')[0]);
    expect(String(selectedGuardian.id)).not.toBe('');
    expect(selectedGuardian.email).toBeUndefined();
    await expect(page.locator('#guardian_first_name')).toHaveValue('Admission');
    await expect(page.locator('#guardian_last_name')).toHaveValue(`Guardian ${suffix}`);
    await expect(page.locator('#guardian_mobile')).toHaveValue(`091${String(suffix).slice(-7)}`);
    await expect(page.locator('#guardian_first_name')).not.toBeEditable();
    await expect(page.locator('#guardian_last_name')).not.toBeEditable();
    await expect(page.locator('#guardian_mobile')).not.toBeEditable();

    const clearSelectedGuardian = async () => {
        const clear = page.locator('#guardian_admission_guardian_id + .select2 .select2-selection__clear');
        await expect(clear).toBeVisible();
        await clear.click();
        await expect(page.locator('input[name="guardian_email"]')).toHaveValue('');
        await expect(page.locator('#guardian_first_name')).toBeEditable();
        await expect(page.locator('#guardian_last_name')).toBeEditable();
        await expect(page.locator('#guardian_mobile')).toBeEditable();
    };

    // A fresh page has no in-memory Select2 result catalogue. Reproduce the
    // Production native option shape (id/text only) and prove that the
    // tenant-scoped details endpoint rehydrates the authoritative Guardian.
    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });
    const detailResponse = page.waitForResponse((response) => (
        response.url().includes(`/guardian/${selectedGuardian.id}/admission-details`)
        && response.status() === 200
    ));
    await page.locator('#guardian_admission_guardian_id').evaluate((select, guardian) => {
        // The admission source element may be rebound by Select2. Remove the
        // direct test listener so this assertion exercises the durable,
        // document-delegated production listener only.
        $(select).off('.guardianAdmission');
        select.add(new Option(guardian.text, guardian.id, true, true));
        $(select).trigger({
            type: 'select2:select',
            params: { data: guardian },
        });
    }, { id: selectedGuardian.id, text: 'existing-guardian' });
    await detailResponse;
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);
    await expect(page.locator('#guardian_first_name')).toHaveValue('Admission');
    await expect(page.locator('#guardian_last_name')).toHaveValue(`Guardian ${suffix}`);
    await expect(page.locator('#guardian_mobile')).toHaveValue(`091${String(suffix).slice(-7)}`);

    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });
    await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    const matchingGuardianAfterHydration = page.locator('.select2-results__option').filter({ hasText: email }).last();
    await expect(matchingGuardianAfterHydration).toBeVisible();
    await matchingGuardianAfterHydration.click();
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);

    const submittedGuardianFields = await page.locator('#create-form').evaluate((form) => Object.fromEntries(
        ['guardian_email', 'guardian_first_name', 'guardian_last_name', 'guardian_mobile']
            .map((field) => [field, new FormData(form).get(field)])
    ));
    expect(submittedGuardianFields).toEqual({
        guardian_email: email,
        guardian_first_name: 'Admission',
        guardian_last_name: `Guardian ${suffix}`,
        guardian_mobile: `091${String(suffix).slice(-7)}`,
    });

    // Production Select2 reduces its native selected option to id/text. Reproduce
    // that event shape after dropping the selection cache: the result catalog must
    // recover the complete Guardian received from the AJAX result.
    await page.locator('#guardian_admission_guardian_id').evaluate((select, data) => {
        $(select).removeData('guardianAdmissionSelection').trigger({
            type: 'select2:select',
            params: { data },
        });
    }, { id: selectedGuardian.id, text: email });
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);
    await expect(page.locator('#guardian_first_name')).toHaveValue('Admission');
    await expect(page.locator('#guardian_last_name')).toHaveValue(`Guardian ${suffix}`);
    await expect(page.locator('#guardian_mobile')).toHaveValue(`091${String(suffix).slice(-7)}`);

    await clearSelectedGuardian();

    await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    await expect(matchingGuardian).toBeVisible();
    await matchingGuardian.click();
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);

    // A typed email remains the new-Guardian path: it must not reuse stale
    // selected-Guardian details and the required name/mobile fields stay editable.
    const newGuardianEmail = `new-guardian-${suffix}@bowen-qa.test`;
    await clearSelectedGuardian();
    await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(newGuardianEmail);
    const typedGuardian = page.locator('.select2-results__option').filter({ hasText: newGuardianEmail }).last();
    await expect(typedGuardian).toBeVisible();
    await typedGuardian.click();
    await expect(page.locator('#guardian_email')).toHaveValue(newGuardianEmail);
    await expect(page.locator('#guardian_first_name')).toBeEditable();
    await expect(page.locator('#guardian_last_name')).toBeEditable();
    await expect(page.locator('#guardian_mobile')).toBeEditable();

    await clearSelectedGuardian();
    await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
    await page.locator('.select2-container--open .select2-search__field').fill(email);
    await expect(matchingGuardian).toBeVisible();
    await matchingGuardian.click();
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue(email);

    // The capture-phase submit guard must restore every required Guardian
    // field even if another client-side callback cleared form inputs.
    await page.locator('#create-form').evaluate((form) => {
        form.addEventListener('submit', () => {
            window.__guardianAdmissionFormData = Object.fromEntries(new FormData(form).entries());
        }, true);
        ['guardian_email', 'guardian_first_name', 'guardian_last_name', 'guardian_mobile'].forEach((id) => {
            document.getElementById(id).value = '';
        });
    });
    await expect(page.locator('input[name="guardian_email"]')).toHaveValue('');

    await page.locator('select[name="class_section_id"]').selectOption({ index: 1 });
    await page.locator('input[name="first_name"]').fill('Admission');
    await page.locator('input[name="last_name"]').fill(`Student ${suffix}`);
    await page.locator('input[name="mobile"]').fill(`092${String(suffix).slice(-7)}`);
    const dob = page.locator('input[name="dob"]');
    // The legacy datepicker clears typed values unless its input/change events
    // are raised.  Use the same DOM events the picker emits after a date click.
    await dob.evaluate((input) => {
        input.value = '01-01-2015';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await expect(dob).toHaveValue('01-01-2015');
    await page.locator('textarea[name="current_address"]').fill('BOWEN QA local address');
    await page.locator('textarea[name="permanent_address"]').fill('BOWEN QA local address');
    await page.locator('#create-btn').click();
    await expect.poll(() => page.evaluate(() => window.__guardianAdmissionFormData)).toMatchObject({
        guardian_email: email,
        guardian_first_name: 'Admission',
        guardian_last_name: `Guardian ${suffix}`,
        guardian_mobile: `091${String(suffix).slice(-7)}`,
    });
    await expect(page.getByText('Data Stored Successfully')).toBeVisible();
});

test('Student admission keeps the latest canonical Guardian response and blocks failed details', async ({ page }) => {
    await page.goto('/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill('qa_admin@bowen-qa.test');
    await page.locator('input[name="password"]').fill('local-bowen-qa-only');
    await page.locator('input[name="code"]').fill('BOWEN_QA');
    await page.locator('form').filter({ has: page.locator('input[name="email"]') }).evaluate((form) => form.submit());
    await page.waitForURL(/dashboard/);

    const suffix = Date.now();
    const createGuardian = async (email, firstName) => {
        await page.goto('/guardian/create', { waitUntil: 'domcontentloaded' });
        await page.locator('#guardian_first_name').fill(firstName);
        await page.locator('#guardian_last_name').fill(`Race ${suffix}`);
        await page.locator('#guardian_email').fill(email);
        await page.locator('#guardian_mobile').fill(`093${String(suffix).slice(-7)}`);
        await page.locator('#guardian_male').check();
        await page.getByRole('button', { name: 'Submit' }).click();
        await expect(page.getByText('Data Created Successfully')).toBeVisible();
    };
    const firstEmail = `race-a-${suffix}@bowen-qa.test`;
    const secondEmail = `race-b-${suffix}@bowen-qa.test`;
    await createGuardian(firstEmail, 'RaceA');
    await createGuardian(secondEmail, 'RaceB');

    await page.goto('/students/create', { waitUntil: 'domcontentloaded' });
    const choose = async (email) => {
        await page.locator('#guardian_admission_guardian_id + .select2 .select2-selection').click();
        await page.locator('.select2-container--open .select2-search__field').fill(email);
        const option = page.locator('.select2-results__option').filter({ hasText: email }).last();
        await expect(option).toBeVisible();
        await option.click();
    };
    const clear = async () => {
        const clearControl = page.locator('#guardian_admission_guardian_id + .select2 .select2-selection__clear');
        await expect(clearControl).toBeVisible();
        await clearControl.click();
    };

    await choose(firstEmail);
    await expect(page.locator('#guardian_email')).toHaveValue(firstEmail);
    const firstId = await page.locator('#guardian_admission_guardian_id').evaluate((select) => String($(select).val()));
    await clear();

    await page.route(`**/guardian/${firstId}/admission-details`, async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 750));
        await route.continue();
    });
    await choose(firstEmail);
    await clear();
    await choose(secondEmail);
    await expect(page.locator('#guardian_email')).toHaveValue(secondEmail);
    await expect(page.locator('#guardian_first_name')).toHaveValue('RaceB');
    await page.waitForTimeout(900);
    await expect(page.locator('#guardian_email')).toHaveValue(secondEmail);
    await expect(page.locator('#guardian_first_name')).toHaveValue('RaceB');

    const secondId = await page.locator('#guardian_admission_guardian_id').evaluate((select) => String($(select).val()));
    await clear();
    await page.route(`**/guardian/${secondId}/admission-details`, (route) => route.abort());
    await choose(secondEmail);
    await expect(page.getByRole('alert')).toContainText('Unable to load the selected Guardian');
    await expect(page.locator('#guardian_email')).toHaveValue('');
});
