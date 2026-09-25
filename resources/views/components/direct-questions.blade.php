<div class="row">
    <label for="odq_select" class="col-sm-12 col-form-label row mt-3">Direct Questions</label>

    <select id="odq_select" class="odq form-select" name="odq[]" multiple="multiple">
        <option value="discharges">Discharges</option>
        <option value="tearing">Tearing</option>
        </select>
</div>
<script>
    $(document).ready(function() {
        // Target the select element by its class
        $('.odq').select2(
            {
                // Allows users to add options not present in the list
                tags: true,
                // Defines the characters that will separate multiple input tags (comma or space)
                tokenSeparators: [',', ' '],
                // Uses the classic theme for the Select2 dropdown
                theme: "classic",
                // Placeholder text when no option is selected (optional)
                placeholder: "Select or type questions..." 
            }
        );
    });
</script>