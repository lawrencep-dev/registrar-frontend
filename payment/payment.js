const acctFees = [
    { name: "Tuition fee", amount: 15000 },
    { name: "Laboratory fee", amount: 2000 },
    { name: "Miscellaneous fee", amount: 1500 }
];

const acctFeeList = document.getElementById("acct-fee-list");
const acctPaymentAmount = document.getElementById("acct-payment-amount");
const acctPaymentType = document.getElementById("acct-payment-type");
const acctSelectedFee = document.getElementById("acct-selected-fee");
const acctAmountDue = document.getElementById("acct-amount-due");
const acctPaymentForm = document.getElementById("acct-payment-form");
const acctOverviewTotal = document.getElementById("acct-overview-total");
const acctOverviewCount = document.getElementById("acct-overview-count");
let acctCurrentFeeIndex = Number(acctPaymentForm.dataset.selectedFee || 0);

function acctFormatAmount(amount) {
    const formattedAmount = Number(amount).toLocaleString("en-US", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    return `₱ ${formattedAmount}`;
}

function acctCleanAmount(value) {
    const cleanedValue = value.replace(/[^\d.]/g, "");
    const decimalIndex = cleanedValue.indexOf(".");
    if (decimalIndex === -1) {
        return cleanedValue;
    }
    return cleanedValue.slice(0, decimalIndex + 1) + cleanedValue.slice(decimalIndex + 1).replace(/\./g, "");
}

function acctReadAmount(value) {
    const cleanedValue = acctCleanAmount(value);
    if (cleanedValue === "" || cleanedValue === ".") {
        return null;
    }
    const amount = Number(cleanedValue);
    return Number.isFinite(amount) ? amount : null;
}

function acctRenderFees() {
    acctFeeList.innerHTML = acctFees.map((fee, index) => `
        <label class="acct-fee-option${index === acctCurrentFeeIndex ? " acct-fee-selected" : ""}" for="acct-fee-${index}">
            <span class="acct-fee-copy">
                <span class="acct-fee-name">${fee.name}</span>
                <span class="acct-fee-due">Due Nov 30, 2026</span>
            </span>
            <span class="acct-fee-choice">
                <span class="acct-fee-amount">${acctFormatAmount(fee.amount)}</span>
                <input class="acct-fee-radio" id="acct-fee-${index}" type="radio" name="fee" value="${index}"${index === acctCurrentFeeIndex ? " checked" : ""}>
            </span>
        </label>
    `).join("");
    acctOverviewTotal.textContent = acctFormatAmount(acctFees.reduce((total, fee) => total + fee.amount, 0));
    acctOverviewCount.textContent = String(acctFees.length);
    acctSetCurrentFee(acctCurrentFeeIndex);
}

function acctUpdateSummary(amount) {
    acctSelectedFee.textContent = acctFees[acctCurrentFeeIndex].name;
    acctAmountDue.textContent = acctFormatAmount(amount);
}

function acctSetCurrentFee(index) {
    acctCurrentFeeIndex = index;
    const selectedFee = acctFees[index];
    document.querySelectorAll(".acct-fee-option").forEach((option, optionIndex) => {
        option.classList.toggle("acct-fee-selected", optionIndex === index);
    });
    acctPaymentAmount.value = acctFormatAmount(selectedFee.amount);
    acctUpdateSummary(selectedFee.amount);
}

function acctSetPaymentType() {
    const isFullPayment = acctPaymentType.value === "full";
    acctPaymentAmount.readOnly = isFullPayment;
    if (isFullPayment) {
        const selectedFee = acctFees[acctCurrentFeeIndex];
        acctPaymentAmount.value = acctFormatAmount(selectedFee.amount);
        acctUpdateSummary(selectedFee.amount);
    }
}

function acctHandleAmountInput() {
    const cleanedValue = acctCleanAmount(acctPaymentAmount.value);
    const parsedAmount = acctReadAmount(cleanedValue);
    if (parsedAmount === null) {
        acctPaymentAmount.value = cleanedValue;
        acctAmountDue.textContent = acctFormatAmount(0);
        return;
    }

    const selectedFee = acctFees[acctCurrentFeeIndex];
    const boundedAmount = Math.min(Math.max(parsedAmount, 1), selectedFee.amount);
    const amountValue = boundedAmount === parsedAmount ? cleanedValue : String(boundedAmount);
    acctPaymentAmount.value = amountValue;
    acctUpdateSummary(boundedAmount);
}

function acctFormatPaymentAmount() {
    const selectedFee = acctFees[acctCurrentFeeIndex];
    const parsedAmount = acctReadAmount(acctPaymentAmount.value);
    const boundedAmount = parsedAmount === null ? 1 : Math.min(Math.max(parsedAmount, 1), selectedFee.amount);
    acctPaymentAmount.value = acctFormatAmount(boundedAmount);
    acctUpdateSummary(boundedAmount);
}

acctRenderFees();
acctSetPaymentType();
acctPaymentType.addEventListener("change", acctSetPaymentType);
acctPaymentAmount.addEventListener("focus", () => {
    if (!acctPaymentAmount.readOnly) {
        acctPaymentAmount.value = acctCleanAmount(acctPaymentAmount.value);
    }
});
acctPaymentAmount.addEventListener("input", acctHandleAmountInput);
acctPaymentAmount.addEventListener("blur", acctFormatPaymentAmount);
acctFeeList.addEventListener("change", event => {
    if (event.target.matches('input[name="fee"]')) {
        acctSetCurrentFee(Number(event.target.value));
    }
});