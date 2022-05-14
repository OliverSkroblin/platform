import HttpClient from 'src/service/http-client.service';
import FilterMultiSelectPlugin from 'src/plugin/listing/filter-multi-select.plugin'
import Iterator from 'src/helper/iterator.helper';
import DomAccess from 'src/helper/dom-access.helper';
import deepmerge from 'deepmerge';

export default class FilterPropertySelectPlugin extends FilterMultiSelectPlugin {

    static options = deepmerge(FilterMultiSelectPlugin.options, {
        propertyName: '',
        onDemand: false,
        propertyId: '',
        listingOptionIds: [],
        selectedOptions: [],
        propertyDropdownContainerClass: '.filter-multi-select-dropdown',
        propertyLoadingSelector: '.filter-property-loading'
    });

    init() {
        super.init();

        this._client = new HttpClient();

        // get refernces to the dom elements
        this.dropdownContainer = this.el.querySelectorAll(this.options.propertyDropdownContainerClass)[0];
    }

    _registerEvents() {
        if ( this.options.onDemand ) {
            const collapse = this.el;

            /** @deprecated tag:v6.5.0 - Bootstrap v5 uses native HTML elements and events to subscribe to Collapse plugin events */
            if (Feature.isActive('v6.5.0.0')) {
                collapse.addEventListener('show.bs.dropdown', this._onDropdownShow.bind(this));
            } else {
                const $collapse = $(collapse);
                $collapse.on('show.bs.dropdown', this._onDropdownShow.bind(this));
            }
        } else {
            super._registerEvents();
        }
    }

    _onDropdownShow() {
        if ( this.el.querySelectorAll(this.options.propertyLoadingSelector).length > 0 ) {
            this._client.get('/widgets/properties/' + this.options.propertyId, this._setPropertyOptions.bind(this));
        }
    }

    _setPropertyOptions(data) {
        this.dropdownContainer.innerHTML = data;

        const checkboxes = DomAccess.querySelectorAll(this.el, this.options.checkboxSelector);

        let selection = [];

        Iterator.iterate(checkboxes, (checkbox) => {
            if ( !this.options.listingOptionIds.includes(checkbox.id) ) {
                const listItem = checkbox.closest(this.options.listItemSelector);
                listItem.parentNode.removeChild(listItem);
            }

            if ( this.options.selectedOptions.includes(checkbox.id) ) {
                selection.push(checkbox.id);
                checkbox.checked = true;
            }
        });

        this.selection = selection;

        super._registerEvents();
    }

    /**
     * @return {Array}
     * @public
     */
    getLabels() {
        const activeCheckboxes =
            DomAccess.querySelectorAll(this.el, `${this.options.checkboxSelector}:checked`, false);

        let labels = [];

        if (activeCheckboxes) {
            Iterator.iterate(activeCheckboxes, (checkbox) => {
                labels.push({
                    label: checkbox.dataset.label,
                    id: checkbox.id,
                    previewHex: checkbox.dataset.previewHex,
                    previewImageUrl: checkbox.dataset.previewImageUrl,
                });
            });
        } else {
            labels = [];
        }

        return labels;
    }

    /**
     * @public
     */
    refreshDisabledState(filter) {
        // Prevent disabling if propertyName is not set correctly
        if (this.options.propertyName === '') {
            return;
        }

        const activeItems = [];
        const properties = filter[this.options.name];
        const entities = properties.entities;

        if (!entities) {
            this.disableFilter();
            return;
        }

        const property = entities.find(entity => entity.translated.name === this.options.propertyName);
        if (property) {
            activeItems.push(...property.options);
        } else {
            this.disableFilter();
            return;
        }

        const actualValues = this.getValues();

        if (activeItems.length < 1 && actualValues.properties.length === 0) {
            this.disableFilter()
            return;
        } else {
            this.enableFilter();
        }

        if(actualValues.properties.length > 0) {
            return;
        }

        this._disableInactiveFilterOptions(activeItems.map(entity => entity.id));
    }
}
