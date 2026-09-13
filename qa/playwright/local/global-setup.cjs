const path = require('node:path');
const { authenticateLocalBowenQa } = require('./bowen-qa-auth.cjs');

module.exports = async () => {
  await authenticateLocalBowenQa();
  await authenticateLocalBowenQa(
    'qa_head_finance@bowen-qa.test',
    path.join(__dirname, '..', '.auth', 'bowen-qa-head-finance.json'),
  );
};
