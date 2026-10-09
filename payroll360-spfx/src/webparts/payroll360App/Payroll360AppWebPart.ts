import { Version } from '@microsoft/sp-core-library';
import {
  IPropertyPaneConfiguration,
  PropertyPaneTextField
} from '@microsoft/sp-property-pane';
import { BaseClientSideWebPart } from '@microsoft/sp-webpart-base';

import * as strings from 'Payroll360AppWebPartStrings';

export interface IPayroll360AppWebPartProps {
  appUrl: string;
  iframeTitle: string;
}

// Matches "SharePointFullPage" in the manifest's supportedHosts: when this web part
// is the only one on a Single Part App Page, SharePoint hides its own chrome and lets
// the part fill the viewport, so the iframe only needs to fill its own container.
export default class Payroll360AppWebPart extends BaseClientSideWebPart<IPayroll360AppWebPartProps> {

  public render(): void {
    const url = this.properties.appUrl || 'https://www.appz360.com/payroll360-app/';
    const title = this.properties.iframeTitle || 'Payroll360';

    this.domElement.innerHTML = `
      <div class="payroll360-shell" style="position:relative;width:100%;height:100vh;min-height:720px;">
        <iframe
          src="${this.escapeAttr(url)}"
          title="${this.escapeAttr(title)}"
          style="position:absolute;inset:0;width:100%;height:100%;border:0;display:block;"
          allow="clipboard-write; downloads"
        ></iframe>
      </div>
    `;
  }

  private escapeAttr(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  protected get dataVersion(): Version {
    return Version.parse('1.0');
  }

  protected getPropertyPaneConfiguration(): IPropertyPaneConfiguration {
    return {
      pages: [
        {
          header: { description: strings.PropertyPaneDescription },
          groups: [
            {
              groupName: strings.BasicGroupName,
              groupFields: [
                PropertyPaneTextField('appUrl', {
                  label: strings.AppUrlFieldLabel,
                  value: this.properties.appUrl
                }),
                PropertyPaneTextField('iframeTitle', {
                  label: strings.IframeTitleFieldLabel,
                  value: this.properties.iframeTitle
                })
              ]
            }
          ]
        }
      ]
    };
  }
}
